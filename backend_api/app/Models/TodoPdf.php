<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TodoPdf extends Model
{
    use HasFactory;

    protected $fillable = [
        'todo_id',
        'pdf_path',
        'original_name',
        'file_size',
        'mime_type',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    public function todo()
    {
        return $this->belongsTo(Todo::class);
    }

    /**
     * Get the full URL for the PDF file
     */
    public function getPdfUrlAttribute()
    {
        return asset('storage/' . $this->pdf_path);
    }

    /**
     * Get the file size in human readable format
     */
    public function getFormattedFileSizeAttribute()
    {
        if (!$this->file_size) {
            return 'Unknown';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->file_size;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 2) . ' ' . $units[$unit];
    }
}
