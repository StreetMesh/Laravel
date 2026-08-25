<?php

namespace StreetMesh\Protocol\Discovery;

/**
 * The name pointed somewhere, and what it pointed at does not answer to it.
 *
 * The half of handle verification that stops somebody hanging a familiar name on
 * a stranger, so this is the one failure here that must never be presented as an
 * ordinary mistake. It means a server is claiming an identity that disowns the
 * claim, whether by misconfiguration or on purpose, and a caller that treats it
 * as a typo has quietly turned off the check.
 */
final class IdentityDisownsHandle extends DiscoveryFailed {}
