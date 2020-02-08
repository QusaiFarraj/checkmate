<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateLaravelVersionsTable extends Migration
{
    public function up()
    {
        Schema::create('laravel_versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('major');
            $table->string('minor');
            $table->string('patch')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('laravel_versions');
    }
}
