<?php

return [

    /*
     |--------------------------------------------------------------------------
     | What this domicile is called, in pictures
     |--------------------------------------------------------------------------
     |
     | Set separately from the venue's, and rarely set at all. A domicile is the
     | half of a server that holds somebody's identity and their records, and
     | what a person wants to recognise there is the server they chose to live
     | on — usually the plain one the operator runs under, whatever the venue in
     | the same container has been dressed up as.
     |
     | That separation is the whole point of this file existing. A server can be
     | both halves at once, and one mark for the application would tell somebody
     | reading their own records that they were at the games room.
     |
     | A public path with no variant or extension on it. A mark that carries its
     | own ground needs a second drawing for a dark surface, and every pack built
     | for this server puts `-small.svg` and `-dark-small.svg` beside each other
     | under one name — so naming the pair is enough.
     |
     | Unset is the server's own mark.
     |
     */

    'mark' => env('STREETMESH_DOMICILE_MARK'),

];
