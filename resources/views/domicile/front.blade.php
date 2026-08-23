{{-- Greets a stranger. Rendered at the root only if the application says so. --}}
@php
    /*
     * Rendered rather than written out by hand, so the link is styled like every
     * other one on the page and the sentence stays a single thing to translate.
     */
    $streetmesh = Blade::render('<flux:link href="https://streetmesh.com" external>StreetMesh</flux:link>');
@endphp

<flux:text class="text-center">
    {!! __('This is a :streetmesh domicile server.', ['streetmesh' => $streetmesh]) !!}
</flux:text>
