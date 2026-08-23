{{--
    Stands in for the host's *auth* layout — the one with no chrome.

    These packages ship components written against the Livewire starter kit's
    chrome, which is the opinion the project settled on — so a package cannot
    render one of its own screens without a host. That is a real contract rather
    than an oversight, and this is what makes it testable without pretending
    otherwise.
--}}
<html><head><title>{{ $title ?? '' }}</title></head><body>{{ $slot }}</body></html>
