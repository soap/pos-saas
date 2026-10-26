<?php

use App\Http\Controllers\PosShellController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Vue SPA หน้าร้าน — vue-router จัดการ path ย่อยทั้งหมด
// /pos/t/{slug}/... ปักหมุดร้านด้วย path (ต้องลงทะเบียนก่อน /pos/{any?} ที่จับทุก path)
Route::get('/pos/t/{tenant}/{any?}', PosShellController::class)->where('any', '.*')->name('pos.tenant');
Route::get('/pos/{any?}', PosShellController::class)->where('any', '.*')->name('pos');
