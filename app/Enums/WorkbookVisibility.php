<?php

namespace App\Enums;

use Illuminate\Validation\Rule;

/**
 * Who may reach one folder, workbook or row, and what they may do once there.
 *
 * STORED on the resource, unlike TicketViewerRole which is recomputed per
 * request - this is the author's stated intent, not a derived fact. What the
 * viewer actually gets is that intent intersected with every ancestor's, which
 * is WorkbookAccessService's job.
 *
 * Every table defaults to OwnerOnly. A tag that is somehow never set should
 * reach nobody but its author, not the whole estate.
 */
enum WorkbookVisibility: string
{
    /** Only the creator. The default, and the fail-closed case. */
    case OwnerOnly = 'owner_only';

    /** Anyone who can reach the owning store, read-only. */
    case StoreView = 'store_view';

    /** Anyone who can reach the owning store, read-write. */
    case StoreEdit = 'store_edit';

    /** Holders of ANY of the named roles at the owning store, read-only. */
    case StoreRoleView = 'store_role_view';

    /** Holders of any of the named roles at the owning store, read-write. */
    case StoreRoleEdit = 'store_role_edit';

    /** Every authenticated user of this service, read-only. */
    case AllStoresView = 'all_stores_view';

    /** Every authenticated user of this service, read-write. */
    case AllStoresEdit = 'all_stores_edit';

    public function label(): string
    {
        return match ($this) {
            self::OwnerOnly => 'Only me',
            self::StoreView => 'This store — can view',
            self::StoreEdit => 'This store — can edit',
            self::StoreRoleView => 'Specific roles at this store — can view',
            self::StoreRoleEdit => 'Specific roles at this store — can edit',
            self::AllStoresView => 'All stores — can view',
            self::AllStoresEdit => 'All stores — can edit',
        };
    }

    public function audience(): WorkbookAudience
    {
        return match ($this) {
            self::OwnerOnly => WorkbookAudience::Owner,
            self::StoreRoleView, self::StoreRoleEdit => WorkbookAudience::StoreRole,
            self::StoreView, self::StoreEdit => WorkbookAudience::Store,
            self::AllStoresView, self::AllStoresEdit => WorkbookAudience::AllStores,
        };
    }

    /**
     * OwnerOnly grants edit because the owner may always edit their own thing -
     * the carve-out is applied before the tag is ever consulted, so this only
     * matters when composing a chain.
     */
    public function grantsEdit(): bool
    {
        return match ($this) {
            self::OwnerOnly, self::StoreEdit, self::StoreRoleEdit, self::AllStoresEdit => true,
            self::StoreView, self::StoreRoleView, self::AllStoresView => false,
        };
    }

    /** True when the tag is meaningless without at least one role name. */
    public function needsRoles(): bool
    {
        return $this === self::StoreRoleView || $this === self::StoreRoleEdit;
    }

    /** True when the tag is answered without looking at the owning store at all. */
    public function isEstateWide(): bool
    {
        return $this === self::AllStoresView || $this === self::AllStoresEdit;
    }

    /**
     * The tags that reach a given audience, for building the SQL visibility
     * clause. Keeping this here rather than writing the string literals into
     * the query means adding a case cannot silently miss one.
     *
     * @return array<int, string>
     */
    public static function valuesForAudience(WorkbookAudience $audience): array
    {
        return array_values(array_map(
            fn (self $c) => $c->value,
            array_filter(self::cases(), fn (self $c) => $c->audience() === $audience),
        ));
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /**
     * The validation for the tag, shared by folder, workbook and row requests -
     * it is identical on all three.
     *
     * `required_if` because a store_role_* tag with no roles reaches nobody, so
     * it is rejected at the door. Role names are free text: roles are created
     * dynamically in pizzasys and only ever arrive here on a grant, so there is
     * no local list to check them against.
     *
     * @return array<string, mixed>
     */
    public static function rules(bool $required = false): array
    {
        return [
            'visibility' => [$required ? 'required' : 'sometimes', Rule::enum(self::class)],
            'visibility_roles' => [
                'array',
                'max:50',
                'required_if:visibility,'.self::StoreRoleView->value.','.self::StoreRoleEdit->value,
            ],
            'visibility_roles.*' => ['string', 'max:190'],
        ];
    }
}
