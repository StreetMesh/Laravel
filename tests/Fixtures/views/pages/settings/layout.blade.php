{{-- A stand-in for the application's settings chrome. See TestCase. --}}
<div>
    <h1>{{ $heading ?? '' }}</h1>
    <p>{{ $subheading ?? '' }}</p>
    {{ $slot }}
</div>
