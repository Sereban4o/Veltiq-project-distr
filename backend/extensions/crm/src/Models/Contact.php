<?php

namespace App\Extensions\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'crm_contacts';

    protected $fillable = [
        'first_name', 'last_name', 'email', 'phone', 'position', 'company_id', 'notes', 'owner_id',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function deals()
    {
        return $this->hasMany(Deal::class, 'contact_id');
    }

    public function activities()
    {
        return $this->hasMany(Activity::class, 'contact_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
    }
}
