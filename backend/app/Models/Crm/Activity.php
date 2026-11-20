<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Activity extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'crm_activities';

    public const TYPES = ['call', 'meeting', 'task', 'email', 'note'];

    protected $fillable = [
        'type', 'subject', 'description', 'due_at', 'is_completed',
        'deal_id', 'contact_id', 'company_id', 'owner_id',
        'direction', 'outcome', 'duration_seconds', 'phone',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'is_completed' => 'boolean',
        'duration_seconds' => 'integer',
    ];

    public function deal()
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
