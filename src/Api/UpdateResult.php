<?php

declare(strict_types=1);

namespace Chicken\Api;

enum UpdateResult
{
    case Saved;
    case Rejected;      // la modification a été refusée (ex. pseudo réservé) : le fichier est inchangé
    case Failed;        // le fichier n'a pas pu être ouvert, verrouillé ou écrit
}
