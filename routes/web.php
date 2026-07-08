<?php

use App\Http\Controllers\OAuth\WeChatOAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/oauth/wechat', [WeChatOAuthController::class, 'redirect'])->name('oauth.wechat.redirect');
Route::get('/oauth/wechat/callback', [WeChatOAuthController::class, 'callback'])->name('oauth.wechat.callback');
