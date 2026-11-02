<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Support;

use Workbench\App\Models\Contact;

/**
 * Allowed to edit a contact unless it is being moved to Paris (a rule that only the NEW value breaks).
 */
final class ContactPolicy
{
    public function create(object $user): bool
    {
        return true;
    }

    public function update(object $user, Contact $contact): bool
    {
        return $contact->city !== 'Paris';
    }
}
