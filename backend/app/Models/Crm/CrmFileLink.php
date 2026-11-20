<?php

namespace App\Models\Crm;

use App\Models\File;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CrmFileLink extends Model
{
    protected $table = 'crm_file_links';

    protected $fillable = ['file_id', 'linkable_type', 'linkable_id', 'linked_by'];

    public function file()
    {
        return $this->belongsTo(File::class, 'file_id');
    }

    public function linkable()
    {
        return $this->morphTo();
    }

    public function linker()
    {
        return $this->belongsTo(User::class, 'linked_by');
    }
}
