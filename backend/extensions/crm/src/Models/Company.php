<?php

namespace App\Extensions\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'crm_companies';

    protected $fillable = [
        'name', 'website', 'phone', 'email', 'address', 'legal_address', 'industry', 'notes', 'owner_id',
        'ref_1c', 'code_1c', 'full_name', 'inn', 'kpp', 'legal_physical_person',
        'registration_country', 'registration_number', 'tax_number', 'okpo_code',
        'head_counterpart', 'partner_1c', 'international_description',
        'transcription_description', 'additional_information', 'contact_info',
        'deleted_in_1c', 'synced_at',
    ];

    protected $casts = [
        'contact_info' => 'array',
        'deleted_in_1c' => 'boolean',
        'synced_at' => 'datetime',
    ];

    /** Only the companies linked to the 1С counterparties catalogue. */
    public function scopeCounterparties($query)
    {
        return $query->whereNotNull('ref_1c');
    }

    public function contacts()
    {
        return $this->hasMany(Contact::class, 'company_id');
    }

    public function deals()
    {
        return $this->hasMany(Deal::class, 'company_id');
    }

    public function activities()
    {
        return $this->hasMany(Activity::class, 'company_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
