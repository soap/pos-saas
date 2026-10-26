<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Vue SPA หน้าร้าน — vue-router จัดการ path ย่อยทั้งหมดใต้ /pos
Route::view('/pos/{any?}', 'pos')->where('any', '.*')->name('pos');
