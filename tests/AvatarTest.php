<?php

namespace StreetMesh\Server\Tests;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use StreetMesh\Protocol\Cid;
use StreetMesh\Server\Domicile\Avatars\Avatars;
use StreetMesh\Server\Domicile\Avatars\Icon;
use StreetMesh\Server\Domicile\Residents\Handle;
use StreetMesh\Server\Domicile\Residents\Residents;
use StreetMesh\Server\Protocol\Blobs\BlobStore;
use StreetMesh\Server\Protocol\Capabilities\Capabilities;
use StreetMesh\Server\Protocol\Identity\Identities;
use StreetMesh\Server\Protocol\Identity\Identity;
use StreetMesh\Server\Protocol\Records\Record;
use StreetMesh\Server\Tests\Fixtures\Resident;

/**
 * A face that belongs to the person, served from their own address.
 *
 * Two halves, and the second is the one worth the most. Storing a picture is
 * ordinary. Serving it from `alice.home.test/avatar/icon` is the claim this
 * feature makes, and it is one line of middleware away from silently becoming
 * a redirect that every browser then caches permanently.
 */
class AvatarTest extends TestCase
{
    /**
     * Its subject is somebody who lives here, so here is where they live.
     *
     * The suite's default is a venue at `games.test` visited by people from
     * elsewhere. This half means the opposite by "here".
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->livesHere($app);

        $app['config']->set('streetmesh.blobs.disk', 'blobs');
        $app['config']->set('filesystems.disks.blobs', ['driver' => 'local', 'root' => storage_path('app/blobs')]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('blobs');
    }

    private function avatars(): Avatars
    {
        return $this->app->make(Avatars::class);
    }

    /**
     * A real resident, with a real name under this server's own.
     */
    private function alice(string $label = 'alice'): Identity
    {
        $this->app->make(Identities::class)->forServer();

        return $this->app->make(Residents::class)->settle(
            Resident::create(['name' => 'Alice', 'email' => $label.'@home.test', 'password' => 'irrelevant']),
            Handle::for($label, 'home.test'),
        )['identity'];
    }

    /** A picture that is not square, so cropping has something to do. */
    private function uploaded(int $width = 120, int $height = 60): string
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagefilledrectangle($canvas, 0, 0, $width, $height, imagecolorallocate($canvas, 200, 30, 30));

        ob_start();
        imagejpeg($canvas);
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);

        return $bytes;
    }

    // ── What gets stored ────────────────────────────────────────────────────

    /**
     * Never the bytes that arrived.
     *
     * These come back from the origin that answers for somebody's identity, so
     * whatever was uploaded is decoded and written again as this server's own
     * PNG. That is what makes an SVG full of script, or a JPEG with a payload
     * glued to the end of it, not a thing this server will hand to anybody.
     */
    public function test_what_is_kept_is_this_servers_own_png_and_not_what_arrived(): void
    {
        $uploaded = $this->uploaded();

        $avatar = $this->avatars()->adopt($this->alice(), $uploaded);

        $icon = $avatar->icon();

        $this->assertNotNull($icon);
        $this->assertSame('image/png', $icon->mime);
        $this->assertNotSame((string) Cid::forRaw($uploaded), $avatar->icon_cid);
    }

    public function test_an_icon_is_square_whatever_shape_it_arrived_in(): void
    {
        $avatar = $this->avatars()->adopt($this->alice(), $this->uploaded(300, 100));

        $bytes = (string) $this->app->make(BlobStore::class)
            ->bytes($avatar->icon());

        [$width, $height] = (array) getimagesizefromstring($bytes);

        $this->assertSame(Icon::SIZE, $width);
        $this->assertSame(Icon::SIZE, $height);
    }

    public function test_something_that_is_not_an_image_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->avatars()->adopt($this->alice(), '<svg xmlns="http://www.w3.org/2000/svg"><script/></svg>');
    }

    /**
     * The record is the durable fact and the row beside it is only an index.
     *
     * Public, because that is what the collection declares — and a private one
     * would mean a permalink that answers with nothing.
     */
    public function test_choosing_a_face_writes_a_public_record_and_a_projection(): void
    {
        $alice = $this->alice();

        $avatar = $this->avatars()->adopt($alice, $this->uploaded(), 'Weekday');

        $record = Record::query()->where('did', $alice->did)->where('rkey', $avatar->rkey)->first();

        $this->assertNotNull($record);
        $this->assertSame(Avatars::COLLECTION, $record->collection);
        $this->assertSame(Record::PUBLIC, $record->visibility);
        $this->assertSame('Weekday', $record->value['name']);

        // ATProtocol's shape for pointing at bytes, so their software can
        // follow it without learning a second convention.
        $this->assertSame('blob', $record->value['icon']['$type']);
        $this->assertSame($avatar->icon_cid, $record->value['icon']['ref']['$link']);

        // The half nothing writes yet, present and honest about being empty.
        $this->assertNull($record->value['model']);
    }

    /**
     * Records accumulate; the projection does not.
     *
     * A record that could be edited would change what everybody who cited it
     * was citing, so changing your face writes a new one — and the old one
     * standing is how somebody can see what they used to look like.
     */
    public function test_changing_your_face_writes_a_second_record_and_keeps_the_first(): void
    {
        $alice = $this->alice();

        $first = $this->avatars()->adopt($alice, $this->uploaded(120, 60), 'Weekday');
        $second = $this->avatars()->adopt($alice, $this->uploaded(90, 90), 'Weekend');

        $this->assertCount(2, $this->avatars()->history((string) $alice->did));

        $this->assertSame($first->id, $second->id, 'one avatar per resident, for now');
        $this->assertNotSame($first->rkey, $second->rkey);
        $this->assertSame('Weekend', $this->avatars()->defaultFor((string) $alice->did)?->name);
    }

    public function test_somebody_who_has_chosen_nothing_has_no_avatar(): void
    {
        $this->assertNull($this->avatars()->defaultFor((string) $this->alice()->did));
    }

    // ── What the address answers ────────────────────────────────────────────

    /**
     * @return TestResponse<Response>
     */
    private function askHost(string $host, string $path = '/avatar/icon')
    {
        return $this->get('https://'.$host.$path);
    }

    public function test_a_residents_own_hostname_serves_their_icon(): void
    {
        $alice = $this->alice();
        $avatar = $this->avatars()->adopt($alice, $this->uploaded());

        $this->askHost('alice.home.test')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('ETag', '"'.$avatar->icon_cid.'"');
    }

    /**
     * The regression that would kill this feature silently.
     *
     * `SendResidentsHome` sits in the `web` group and permanently redirects
     * everything on a resident hostname to their profile. Moving these routes
     * into that group — or pushing that middleware wider — turns every avatar
     * into a 301 that browsers then cache, and every other test here would
     * still pass.
     */
    public function test_the_permalink_is_not_swallowed_by_the_redirect_to_a_profile(): void
    {
        $this->avatars()->adopt($this->alice(), $this->uploaded());

        $answer = $this->askHost('alice.home.test');

        $answer->assertOk();
        $answer->assertHeaderMissing('Location');

        // And the thing the hostname exists for still answers, unchanged.
        $this->askHost('alice.home.test', '/.well-known/atproto-did')->assertOk();

        // While an ordinary browser route there still goes where it did.
        $this->askHost('alice.home.test', '/directory')->assertRedirect();
    }

    /**
     * A face is never served from a cache without asking first.
     *
     * The path is stable and what is behind it is not, so any lifetime at all
     * is a window in which somebody has changed their face and nobody can see
     * it. That window was five minutes, and what it looked like was publishing
     * a picture at home, walking back to a venue, and still being a letter.
     */
    public function test_a_face_is_revalidated_rather_than_trusted(): void
    {
        $this->alice();

        $this->askHost('alice.home.test')->assertHeader('Cache-Control', 'no-cache, public');

        $this->avatars()->adopt($this->alice('bob'), $this->uploaded());

        $this->askHost('bob.home.test')->assertHeader('Cache-Control', 'no-cache, public');
    }

    /** A browser that already has this picture is told so, without the picture. */
    public function test_a_browser_holding_the_current_face_is_sent_no_bytes(): void
    {
        $avatar = $this->avatars()->adopt($this->alice(), $this->uploaded());

        $answer = $this->withHeaders(['If-None-Match' => '"'.$avatar->icon_cid.'"'])
            ->get('https://alice.home.test/avatar/icon');

        $answer->assertStatus(304);
        $this->assertSame('', $answer->getContent());
    }

    /**
     * Somebody who lives here always has a face.
     *
     * A refusal would be the honest answer to "is there a picture", but that is
     * not the question the address asks. Their letter is a real answer, and one
     * every caller can draw without knowing anything about this server.
     */
    public function test_a_resident_who_has_chosen_nothing_is_drawn_as_their_letter(): void
    {
        $this->alice();

        $answer = $this->askHost('alice.home.test')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml');

        $drawn = $answer->getContent();

        $this->assertStringContainsString('<svg', (string) $drawn);

        // Their initial, from the label rather than from the whole handle —
        // every resident of this server shares the rest of it.
        $this->assertStringContainsString('>A<', (string) $drawn);
    }

    /** And no script rides in on a document format served from this origin. */
    public function test_a_letter_cannot_be_read_as_a_page(): void
    {
        $this->alice();

        $this->askHost('alice.home.test')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    }

    /**
     * A letter is cacheable too, and gives way the moment there is a picture.
     *
     * The two kinds of tag cannot collide — one begins `letter-` and the other
     * is a CID — so a browser holding a letter is handed the real thing rather
     * than waiting for anything to expire.
     */
    public function test_a_letter_gives_way_to_a_picture(): void
    {
        $alice = $this->alice();

        $tag = $this->askHost('alice.home.test')->headers->get('ETag');

        $this->assertStringContainsString('letter-', (string) $tag);

        $this->withHeaders(['If-None-Match' => (string) $tag])
            ->get('https://alice.home.test/avatar/icon')
            ->assertStatus(304);

        $avatar = $this->avatars()->adopt($alice, $this->uploaded());

        $this->withHeaders(['If-None-Match' => (string) $tag])
            ->get('https://alice.home.test/avatar/icon')
            ->assertOk()
            ->assertHeader('ETag', '"'.$avatar->icon_cid.'"');
    }

    /** Two residents are not drawn the same, so a row of letters is legible. */
    public function test_each_resident_gets_their_own_ground(): void
    {
        $this->alice('alice');
        $this->alice('bob');

        $this->assertNotSame(
            $this->askHost('alice.home.test')->getContent(),
            $this->askHost('bob.home.test')->getContent(),
        );
    }

    /** The server has an identity of its own, and it is not a person. */
    public function test_this_servers_own_name_has_no_face(): void
    {
        $this->avatars()->adopt($this->alice(), $this->uploaded());

        $this->askHost('home.test')->assertNotFound();
    }

    /**
     * And the one case that is still a refusal.
     *
     * A resident always has a face; a name nobody goes by has none to have.
     * Drawing a letter here would be this server inventing a person, and would
     * make every name on the internet look like somebody who lives here.
     */
    public function test_a_name_nobody_here_goes_by_answers_with_nothing(): void
    {
        $this->avatars()->adopt($this->alice(), $this->uploaded());

        $this->askHost('mallory.home.test')->assertNotFound();
    }

    /** One person's address does not serve another person's picture. */
    public function test_each_resident_answers_only_for_themselves(): void
    {
        $this->avatars()->adopt($this->alice('alice'), $this->uploaded());
        $this->alice('bob');

        $this->askHost('alice.home.test')->assertHeader('Content-Type', 'image/png');

        // bob lives here and has chosen nothing, so he is his letter — not
        // alice's picture, which is the failure this is watching for.
        $this->askHost('bob.home.test')->assertHeader('Content-Type', 'image/svg+xml');
    }

    // ── The screens ─────────────────────────────────────────────────────────

    /**
     * A stranger checking what somebody looks like.
     *
     * Half the reason this is served from the resident's own address: a person
     * who has met "alice" elsewhere can come here and see whether that is what
     * alice looks like. So the profile has to point at the real one.
     */
    public function test_a_profile_shows_the_face_from_the_residents_own_address(): void
    {
        $alice = $this->alice();
        $avatar = $this->avatars()->adopt($alice, $this->uploaded());

        $this->get('https://home.test/profile/alice.home.test')
            ->assertOk()
            ->assertSee('https://alice.home.test/avatar/icon?'.$avatar->icon_cid, escape: false);
    }

    /**
     * Including somebody who has published nothing — their server answers for
     * them either way, so this page points rather than deciding.
     */
    public function test_a_profile_points_at_the_address_even_before_a_picture(): void
    {
        $this->alice();

        $this->get('https://home.test/profile/alice.home.test')
            ->assertOk()
            ->assertSee('https://alice.home.test/avatar/icon', escape: false);
    }

    /**
     * The capability offers it; the application places it.
     *
     * A domicile has something a resident decides about themselves, so it says
     * so — and where that ends up is the settings screen's business rather than
     * this package's. What is checked here is only the offer.
     */
    public function test_the_domicile_offers_an_avatars_page_to_the_settings_screen(): void
    {
        $offered = $this->app->make(Capabilities::class)->settings();

        $this->assertSame(
            [['label' => 'Avatars', 'route' => 'domicile.avatar', 'icon' => 'user-circle']],
            $offered,
        );

        // And the route it names is real, which is the half an array of strings
        // cannot promise on its own.
        $this->assertSame('/settings/avatar', route($offered[0]['route'], absolute: false));
    }

    /** Deciding is behind a login; publishing is not. */
    public function test_choosing_a_face_is_behind_a_login(): void
    {
        $this->get('https://home.test/settings/avatar')->assertRedirect();
    }

    public function test_a_resident_can_reach_the_screen_that_chooses_one(): void
    {
        $this->app->make(Identities::class)->forServer();

        $user = Resident::create(['name' => 'Alice', 'email' => 'alice@home.test', 'password' => 'irrelevant']);

        $this->app->make(Residents::class)->settle($user, Handle::for('alice', 'home.test'));

        $this->actingAs($user)->get('https://home.test/settings/avatar')
            ->assertOk()
            ->assertSee('alice.home.test/avatar/icon');
    }

    /**
     * The model is reserved, not aliased.
     *
     * Answering the icon here would make this server assert that a picture is
     * somebody's model, which is the confusion two paths exist to prevent.
     */
    public function test_the_model_is_reserved_and_says_so(): void
    {
        $this->avatars()->adopt($this->alice(), $this->uploaded());

        $this->askHost('alice.home.test', '/avatar')->assertNotFound();
    }
}
