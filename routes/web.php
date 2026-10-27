<?php

use App\Http\Controllers\MediaController;
use App\Http\Controllers\PosShellController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// ไฟล์ media (private) — ต้องมาพร้อมลายเซ็นจาก temporarySignedRoute; relative เพื่อใช้ได้ทุกโดเมนของร้าน
Route::get('/media/{uuid}/{conversion?}', MediaController::class)
    ->whereUuid('uuid')->whereAlpha('conversion')->middleware('signed:relative')->name('media.show');

// Vue SPA หน้าร้าน — vue-router จัดการ path ย่อยทั้งหมด
// /pos/t/{slug}/... ปักหมุดร้านด้วย path (ต้องลงทะเบียนก่อน /pos/{any?} ที่จับทุก path)
Route::get('/pos/t/{tenant}/{any?}', PosShellController::class)->where('any', '.*')->name('pos.tenant');
Route::get('/pos/{any?}', PosShellController::class)->where('any', '.*')->name('pos');
