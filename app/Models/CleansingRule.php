<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-tenant cleansing transform layered on the firewall's defaults. See
 * claude/data-quality-firewall.md.
 */
class CleansingRule extends Model
{
    public const TYPES = [
        'trim'                => 'Trim whitespace',
        'collapse_ws'         => 'Collapse internal whitespace',
        'upper'               => 'Uppercase',
        'lower'               => 'Lowercase',
        'strip_leading_zeros' => 'Strip leading zeros',
        'date_iso'            => 'Parse date → ISO (Y-m-d)',
        'number'              => 'Parse number (strip currency/separators)',
        'default_if_blank'    => 'Default when blank (params.value)',
        'regex_replace'       => 'Regex replace (params.pattern → params.replacement)',
        'value_map'           => 'Map value (params.from → params.to)',
    ];

    protected $fillable = [
        'tenant_id', 'data_type', 'field', 'rule_type', 'params', 'ordinal', 'enabled',
    ];

    protected $casts = [
        'params'  => 'array',
        'ordinal' => 'integer',
        'enabled' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
