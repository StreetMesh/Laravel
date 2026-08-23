{{--
    Stands in for the host's *door* layout — the frame the venue asks for.

    Same contract as the auth stub next to it: these packages ship components
    written against the host's chrome, so a package cannot render one of its own
    screens without one. Naming the layout is what makes that contract explicit,
    and this is what makes it testable without a host present.
--}}
<html><head><title>{{ $title ?? '' }}</title></head><body>{{ $slot }}</body></html>
