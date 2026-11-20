<?php

namespace App\Models\Crm;

use App\Models\File;
use App\Models\User;
use App\Observers\DealObserver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deal extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'crm_deals';

    public const STATUS_OPEN = 'open';

    public const STATUS_WON = 'won';

    public const STATUS_LOST = 'lost';

    protected $fillable = [
        'title', 'company_id', 'contact_id', 'stage_id', 'amount', 'currency',
        'probability', 'expected_close_date', 'status', 'description', 'owner_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'probability' => 'integer',
        'expected_close_date' => 'date',
        'closed_at' => 'datetime',
    ];

    /**
     * Record stage transitions and keep `closed_at` in sync for every code
     * path that writes a deal (API, CSV import, automations...).
     */
    protected static function booted(): void
    {
        static::observe(DealObserver::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function stage()
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    public function activities()
    {
        return $this->hasMany(Activity::class, 'deal_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** Files attached to the deal through the polymorphic crm_file_links table. */
    public function files()
    {
        return $this->morphToMany(File::class, 'linkable', 'crm_file_links', 'linkable_id', 'file_id')
            ->withPivot('linked_by')
            ->withTimestamps();
    }
}
