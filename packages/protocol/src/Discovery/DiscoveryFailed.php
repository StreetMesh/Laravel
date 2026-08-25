<?php

namespace StreetMesh\Protocol\Discovery;

use RuntimeException;

/**
 * Somewhere between a name somebody typed and the server that answers for it,
 * something did not.
 *
 * The chain has several links — a name resolves to an identity, the identity
 * agrees it answers to the name, the identity resolves to a document, the
 * document names a server — and until now every one of them failed as a plain
 * `RuntimeException` distinguishable only by the sentence inside it.
 *
 * That is fine for a log and useless for a caller. A venue asked to connect
 * somebody has to *do* different things: an address nobody has taken can be
 * offered to whoever typed it, a misspelling should say so, and an identity
 * whose own document disowns the name is a server misbehaving and must never be
 * smoothed over into either. Reading them apart by matching on message text
 * would make those sentences load-bearing, and they are prose.
 *
 * So the reason has a type. The sentences stay what they are — an explanation
 * for whoever reads the log.
 */
abstract class DiscoveryFailed extends RuntimeException {}
