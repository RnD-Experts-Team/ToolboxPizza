<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BreakType;

class BreakTypeController extends Controller
{
    /**
     * The break picker: selectable types in display order.
     *
     * `group` is how the client blocks and headers them; `counts_toward_limit`
     * is the arithmetic. They agree for every seeded row, but a client must
     * read the boolean, not the group, when it explains a total.
     *
     * @return array<string, mixed>
     */
    public function index(): array
    {
        return ['data' => BreakType::query()->selectable()->get()->map(fn (BreakType $type) => [
            'id' => $type->id,
            'slug' => $type->slug,
            'name' => $type->name,
            'group' => $type->group->value,
            'group_label' => $type->group->label(),
            'counts_toward_limit' => $type->counts_toward_limit,
            'requires_custom_label' => $type->requires_custom_label,
            'sort_order' => $type->sort_order,
        ])->all()];
    }
}
