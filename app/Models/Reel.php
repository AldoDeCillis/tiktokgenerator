<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

 class Reel extends Model
  {
      use HasFactory;

      protected $fillable = [
          'argomento',
          'script',
          'video_path',
          'social_post_id',
          'status',
          'error_message',
      ];
  }
