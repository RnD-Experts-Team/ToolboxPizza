<?php

namespace App\Enums;

/**
 * WHO a visibility tag lets in, with the power it grants stripped out.
 *
 * Separated from WorkbookVisibility because "most restrictive wins" has to
 * compare two different axes independently: a store_view and a store_edit
 * reach the SAME people, and only differ in what those people may do. Folding
 * the two into one ordering would make store_role_edit look broader than
 * store_view, which is backwards.
 *
 * The cases are ordered by containment and each really is a subset of the next:
 * an owner holds their own resource, role holders at a store are a subset of
 * everyone who can reach that store, and every store is a subset of everyone.
 * That containment is what makes min() a correct intersection.
 */
enum WorkbookAudience: int
{
    case Owner = 0;
    case StoreRole = 1;
    case Store = 2;
    case AllStores = 3;

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Only me',
            self::StoreRole => 'Specific roles at this store',
            self::Store => 'This store',
            self::AllStores => 'All stores',
        };
    }

    /**
     * The narrower of two audiences. The whole of "most restrictive wins" on
     * this axis.
     */
    public function narrowerOf(self $other): self
    {
        return $this->value <= $other->value ? $this : $other;
    }
}
