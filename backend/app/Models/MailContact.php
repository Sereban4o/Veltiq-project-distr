<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MailContact extends Model
{
    use HasFactory;

    protected $fillable = [
        'email', 'name', 'created_by',
    ];

    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = mb_strtolower(trim((string) $value));
    }

    public function setNameAttribute(?string $value): void
    {
        $name = trim((string) $value);
        $this->attributes['name'] = $name === '' ? null : $name;
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
