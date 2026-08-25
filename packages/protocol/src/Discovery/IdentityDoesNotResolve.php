<?php

namespace StreetMesh\Protocol\Discovery;

/**
 * The name pointed at an identity, and that identity could not be looked up.
 *
 * Usually says more about the asker than the asked. A `did:plc` is only as
 * findable as the directory being consulted, so a development server pointed at
 * its own directory cannot resolve a production identity — and the name, the
 * spelling and the person are all perfectly fine.
 */
final class IdentityDoesNotResolve extends DiscoveryFailed {}
