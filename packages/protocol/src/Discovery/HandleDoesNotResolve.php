<?php

namespace StreetMesh\Protocol\Discovery;

/**
 * Nothing answers for that name.
 *
 * Neither `/.well-known/atproto-did` nor a DNS TXT record, which is what it
 * looks like both when a name is misspelled and when it is spelled perfectly and
 * nobody has taken it. Telling those two apart means asking the *domicile* in
 * the name whether it exists and takes registrations, and that is a question
 * only something that wants to offer the name has any reason to ask.
 */
final class HandleDoesNotResolve extends DiscoveryFailed {}
