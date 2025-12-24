<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Http\Controllers\SignUp\SignUpController;
use App\Http\Controllers\Login\LoginController;
use App\Http\Controllers\Peminjaman\PeminjamanController;
use App\Http\Controllers\Peminjaman\PeminjamanDetailController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\EditakundanHapusakun\AkunController;
use App\Http\Controllers\CariRuangan\CariRuanganController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\RuanganController;
use App\Http\Controllers\Riwayat\RiwayatController;
use App\Http\Controllers\Admin\AdminPertanyaanController;
use App\Http\Controllers\KirimPertanyaanController;

// Navbar
Route::get('/home', function () {
    $riwayat = collect();

    if (Auth::check()) {
        $userId = Auth::id();

        $riwayat = DB::table('peminjaman as p')
            ->join('ruangan as r', 'p.ruanganid', '=', 'r.ruanganid')
            ->join('riwayat_status as rs', function ($join) {
                $join->on('p.peminjamanid', '=', 'rs.peminjamanid')
                     ->whereRaw('rs.statusid = (
                         SELECT MAX(statusid)
                         FROM riwayat_status
                         WHERE peminjamanid = p.peminjamanid
                     )');
            })
            ->where('p.userid', $userId)
            ->orderByDesc('p.tanggal')
            ->limit(2)
            ->get([
                'p.peminjamanid',
                'p.tanggal',
                'p.nama_shift',
                'p.keterangan',
                'r.nama_ruangan',
                'r.kapasitas',
                'rs.nama_status',
            ]);
    }

    return view('page/homepage', compact('riwayat'));
})->name('home');

Route::get('/riwayat', [RiwayatController::class, 'riwayat'])->name('riwayat');

// Detail Peminjaman
Route::get('/peminjaman/{peminjamanid}', [PeminjamanDetailController::class, 'show'])
    ->name('peminjaman.detail')
    ->middleware('auth');

Route::post('/peminjaman/{peminjamanid}/cancel', [PeminjamanDetailController::class, 'cancel'])
    ->name('peminjaman.cancel')
    ->middleware('auth');

Route::get('/peminjaman/{peminjamanid}/download-dokumen', [PeminjamanDetailController::class, 'downloadDokumen'])
    ->name('peminjaman.download-dokumen')
    ->middleware('auth');

Route::get('/search', [CariRuanganController::class, 'index'])->name('search');
Route::post('/search/filter', [CariRuanganController::class, 'search'])->name('search.filter');

Route::get('/info', function () {
    return view('/page/info/alur-penjelasan');
})->name('info');

Route::get('/profile', [ProfileController::class, 'show'])->name('profile');

//DB Connection
Route::get('/cek-koneksi', function () {
    $peminjaman = DB::table('peminjaman')
        ->join('app_user', 'peminjaman.userid', '=', 'app_user.userid')
        ->select('peminjaman.peminjamanid', 'app_user.nama', 'peminjaman.keterangan', 'peminjaman.dokumen')
        ->get();

    return view('cek-koneksi', ['peminjaman' => $peminjaman]);
});

// Auth
Route::get('/', function () {
    return view('page/auth/auth');
});

// LOGIN
Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'login'])->name('login.process');

// LOGOUT (ini yang tadi bikin error karena belum ada)
Route::post('/logout', function (Request $request) {
    $request->session()->flush();      // hapus semua session user
    return redirect()->route('login'); // balik ke halaman login
})->name('logout');

// SIGNUP
Route::get('/signup', [SignUpController::class, 'create'])->name('signup');
Route::post('/signup', [SignUpController::class, 'store'])->name('signup.store');

// ====== RUANGAN & PEMINJAMAN ROUTES ======

// Detail Ruangan (Static - route lama)
Route::get('/ruangan', function () {
    return view('page/ruangan/detail-ruangan');
})->name('ruangan.static');

// ====== ADMIN ROUTES (Harus sebelum dynamic routes) ======
Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('admin.dashboard');
    Route::post('/peminjaman/{peminjamanId}/status', [AdminController::class, 'updateStatus'])->name('admin.peminjaman.updateStatus');

    // Ruangan Routes - HARUS SEBELUM /ruangan/{id}
    Route::get('/ruangan/index', [RuanganController::class, 'index'])->name('admin.ruangan.index');
    Route::get('/ruangan/create', [RuanganController::class, 'create'])->name('admin.ruangan.create');
    Route::post('/ruangan', [RuanganController::class, 'store'])->name('admin.ruangan.store');
    Route::get('/ruangan/{id}/edit', [RuanganController::class, 'edit'])->name('admin.ruangan.edit');
    Route::post('/ruangan/{id}', [RuanganController::class, 'update'])->name('admin.ruangan.update');
    Route::delete('/ruangan/{id}', [RuanganController::class, 'destroy'])->name('admin.ruangan.destroy');

    // Pertanyaan Routes
    Route::get('/pertanyaan', [AdminPertanyaanController::class, 'index'])->name('admin.pertanyaan.index');
    Route::get('/pertanyaan/{id}', [AdminPertanyaanController::class, 'show'])->name('admin.pertanyaan.show');
    Route::post('/pertanyaan/{id}/jawab', [AdminPertanyaanController::class, 'store'])->name('admin.pertanyaan.store');
    Route::put('/pertanyaan/{id}/jawab', [AdminPertanyaanController::class, 'update'])->name('admin.pertanyaan.update');
    Route::delete('/pertanyaan/{id}/jawab', [AdminPertanyaanController::class, 'destroy'])->name('admin.pertanyaan.destroy');
});

// Detail Ruangan (Dynamic - route baru dengan ID) - SETELAH admin routes
Route::get('/ruangan/{id}', [PeminjamanController::class, 'show'])
    ->name('ruangan.detail');

// ====== PEMINJAMAN ROUTES (Requires Auth) ======
Route::middleware(['auth'])->group(function () {

    Route::post('/peminjaman', [PeminjamanController::class, 'createPeminjaman'])
        ->name('peminjaman.store');

    Route::post('/peminjaman/check-availability', [PeminjamanController::class, 'checkAvailability'])
        ->name('peminjaman.check-availability');

    Route::get('/success', function () {
        return view('page/peminjaman/success');
    })->name('peminjaman.success');

    Route::post('/peminjaman/slots', [PeminjamanController::class, 'getAvailableSlots'])
    ->name('peminjaman.slots');

});


// Success
Route::get('/success', function () {
    return view('page/peminjaman/success');
});

// Cariruangan
Route::get('/cariruangan', [CariRuanganController::class, 'index'])->name('cari-ruangan.index');
// AJAX search endpoint used by the Cari Ruangan page (filters + realtime search)
Route::post('/cari-ruangan/search', [CariRuanganController::class, 'search'])->name('cari-ruangan.search');

//Cariruangan parsial
Route::get('/cariruanganparsial', function () {
    return view('page/cariruangan/cariruanganparsial');
});

//Logout
Route::get('/logout', function () {
    return view('page/auth/logout');
});

// Signup/login/profile are handled by controllers above (keep routes centralized)

//Riwayat: Peminjaman Dibatalkan
Route::get('/batal', function () {
    return view('page/riwayat/batal');
});

//Riwayat: Peminjaman Diselesaikan
Route::get('/selesai', function () {
    return view('page/riwayat/selesai');
});

//Riwayat: Dalam Peminjaman
Route::get('/dalam', function () {
    return view('page/riwayat/dalam');
});

//Riwayat: Konfirmasi Pembatalan
Route::get('/konfirmasi', function () {
    return view('page/riwayat/konfirmasi');
});

//Riwayat: Permintaan Gagal Dibatalkan
Route::get('/fail', function () {
    return view('page/riwayat/fail');
});



// Edit akun
Route::get('/editakun', [AkunController::class, 'edit'])->name('account.edit');
Route::post('/editakun', [AkunController::class, 'update'])->name('account.update');

// Hapus akun
Route::post('/hapus-akun', [AkunController::class, 'destroy'])->name('account.destroy');

// Route untuk mendapatkan detail ruangan
Route::get('/search/room/{id}', [CariRuanganController::class, 'show'])->name('search.show');

// Route untuk mendapatkan semua fasilitas
Route::get('/search/facilities', [CariRuanganController::class, 'getFacilities'])->name('search.facilities');

// Route untuk cek ketersediaan ruangan
Route::post('/search/check-availability', [CariRuanganController::class, 'checkAvailability'])->name('search.check-availability');
// Route untuk booking ruangan
Route::post('/search/booking', [CariRuanganController::class, 'booking'])->name('search.booking');

// Route untuk konfirmasi booking
Route::post('/search/confirm-booking', [CariRuanganController::class, 'confirmBooking'])->name('search.confirm-booking');

Route::get('/kirimpertanyaan', [KirimPertanyaanController::class, 'create'])->name('kirimpertanyaan.create')->middleware('auth');
Route::post('/kirimpertanyaan', [KirimPertanyaanController::class, 'store'])->name('kirimpertanyaan.store')->middleware('auth');
Route::get('/kirimpertanyaan/{id}', [KirimPertanyaanController::class, 'show'])->name('kirimpertanyaan.show')->middleware('auth');

// faq
Route::get('/faq', function () {
    return view('page/info/faq');
})->name('faq');
