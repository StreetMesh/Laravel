<?php

namespace StreetMesh\Server\Protocol\Records;

/**
 * A record arrived from somewhere else and was kept.
 *
 * Announced so that the parts of this server which keep an index beside the
 * records can bring it up to date, without the endpoint that received it having
 * to know any of them exist. A repository accepts records of kinds nobody here
 * has heard of — that is the whole design — so it cannot be the thing that
 * decides what a record means.
 *
 * Only for records written *for* a resident by somebody holding their
 * permission. A resident writing their own goes through whatever wrote it,
 * which already knows what it just did and updates its own index in the same
 * transaction. Announcing that one as well would mean the same projection
 * happening twice for one write, and the second time from outside the
 * transaction it belonged to.
 */
final readonly class RecordWritten
{
    public function __construct(public Record $record) {}
}
