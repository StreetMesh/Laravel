<?php

namespace StreetMesh\Server\Venue\Experiences;

use StreetMesh\Protocol\ClientMetadata;

/**
 * Everything this venue can offer, in the order it was installed.
 *
 * A venue with none is a perfectly good venue — somewhere people arrive and
 * find nothing on, which is honest and is what a fresh install looks like.
 */
final class Experiences
{
    /** @var array<string, Experience> */
    private array $offered = [];

    public function register(Experience $experience): void
    {
        $this->offered[$experience->name()] = $experience;
    }

    /**
     * @return array<int, Experience>
     */
    public function all(): array
    {
        return array_values($this->offered);
    }

    public function get(string $name): ?Experience
    {
        return $this->offered[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->offered[$name]);
    }

    /**
     * Everything a visitor is asked to agree to, once.
     *
     * The union of what is installed, so a venue asks for exactly what it can
     * use. Somebody arriving at a venue with chess and a shop agrees to both at
     * the door rather than being interrupted later — and a venue that installs
     * nothing asks only to confirm who they are.
     *
     * @param  array<int, string>  $also  anything the operator adds by hand
     * @return array<int, string>
     */
    public function scopes(array $also = []): array
    {
        $asked = [ClientMetadata::BASE_SCOPE, ...$also];

        foreach ($this->offered as $experience) {
            $asked = [...$asked, ...$experience->scopes()];
        }

        return array_values(array_unique($asked));
    }
}
