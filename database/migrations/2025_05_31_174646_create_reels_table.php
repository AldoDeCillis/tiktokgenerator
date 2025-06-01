  <?php

  use Illuminate\Database\Migrations\Migration;
  use Illuminate\Database\Schema\Blueprint;
  use Illuminate\Support\Facades\Schema;

  class CreateReelsTable extends Migration
  {
      public function up()
      {
          Schema::create('reels', function (Blueprint $table) {
              $table->id();
              $table->string('argomento');
              $table->text('script')->nullable();
              $table->string('video_path')->nullable();
              $table->string('social_post_id')->nullable();
              $table->enum('status', ['pending', 'script_generated', 'processing', 'video_ready', 'publishing', 'published', 'error'])
                    ->default('pending');
              $table->text('error_message')->nullable();
              $table->timestamps();
          });
      }

      public function down()
      {
          Schema::dropIfExists('reels');
      }
  }
