<?php

namespace App\Http\Controllers\Peminjaman;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class PeminjamanController extends Controller
{
    public function show($ruanganId)
    {
        $ruangan = DB::table('ruangan')
            ->where('ruanganid', $ruanganId)
            ->first();

        if (!$ruangan) {
            abort(404, 'Ruangan tidak ditemukan');
        }

        $jadwalTerpakai = DB::table('peminjaman as p')
            ->join('riwayat_status as rs', function ($join) {
                $join->on('p.peminjamanid', '=', 'rs.peminjamanid')
                    ->whereRaw('rs.statusid = (
                        SELECT MAX(statusid)
                        FROM riwayat_status
                        WHERE peminjamanid = p.peminjamanid
                    )');
            })
            ->where('p.ruanganid', $ruanganId)
            ->whereIn('rs.nama_status', ['Menunggu', 'Disetujui'])
            ->where('p.tanggal', '>=', now()->toDateString())
            ->groupBy('p.tanggal')
            ->having(DB::raw('COUNT(DISTINCT p.nama_shift)'), '>=', 4)
            ->pluck('p.tanggal')
            ->all();

        // Format foto - ambil foto pertama jika ada multiple links
        $fotoUrl = null;

        if ($ruangan->foto) {
            // Split foto jika ada multiple links dipisah koma
            $photos = array_filter(array_map('trim', explode(',', $ruangan->foto)));

            if (!empty($photos)) {
                $firstPhoto = $photos[0];

                if (str_starts_with($firstPhoto, 'http://') || str_starts_with($firstPhoto, 'https://')) {
                    $fotoUrl = $firstPhoto;
                } else {
                    $fotoUrl = asset('storage/' . ltrim($firstPhoto, '/'));
                }
            }
        }

        return view('page.peminjaman.detail', [
            'peminjaman' => $ruangan,
            'ruangan' => $ruangan,
            'jadwalTerpakai' => $jadwalTerpakai,
            'fotoUrl' => $fotoUrl
        ]);
    }

    public function createPeminjaman(Request $request)
    {
        // Middleware auth sudah ada, ini cuma extra guard
        if (!Auth::check()) {
            return response()->json([
                'success' => false,
                'message' => 'Anda harus login terlebih dahulu',
            ], 401);
        }

        // Validasi input
        $validator = Validator::make($request->all(), [
            'ruangan_id'  => 'required|integer|exists:ruangan,ruanganid',
            'tanggal'     => 'required|date|after:today',
            'waktu'       => 'required|string|in:sesi1,sesi2,sesi3,sesi4',
            'keterangan'  => 'required|string|max:1000',
            'dokumen'     => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
        ], [
            'tanggal.after' => 'Tanggal peminjaman harus setelah hari ini',
            'waktu.in'      => 'Waktu yang dipilih tidak valid',
            'dokumen.max'   => 'Ukuran file maksimal 5MB',
            'dokumen.mimes' => 'Format file harus PDF, DOC, DOCX, JPG, JPEG, atau PNG',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $userId = Auth::id();

        // Mapping waktu ke nama shift
        $shiftMapping = [
            'sesi1' => 'Sesi 1 (07.00 - 10.00)',
            'sesi2' => 'Sesi 2 (10.15 - 12.45)',
            'sesi3' => 'Sesi 3 (13.30 - 16.00)',
            'sesi4' => 'Sesi 4 (16.00 - 18.30)',
        ];

        $namaShift = $shiftMapping[$request->waktu];

        // Cek ketersediaan: apakah sudah ada booking aktif dengan kombinasi ini
        $sudahDipinjam = DB::table('peminjaman as p')
            ->join('riwayat_status as rs', function ($join) {
                $join->on('p.peminjamanid', '=', 'rs.peminjamanid')
                    ->whereRaw('rs.statusid = (
                        SELECT MAX(statusid)
                        FROM riwayat_status
                        WHERE peminjamanid = p.peminjamanid
                    )');
            })
            ->where('p.ruanganid', $request->ruangan_id)
            ->where('p.tanggal', $request->tanggal)
            ->where('p.nama_shift', $namaShift)
            ->whereIn('rs.nama_status', ['Menunggu', 'Disetujui'])
            ->exists();

        if ($sudahDipinjam) {
            return response()->json([
                'success' => false,
                'message' => 'Ruangan sudah dipinjam pada tanggal dan waktu tersebut',
            ], 409);
        }

        DB::beginTransaction();

        try {
            // Upload dokumen ke Supabase jika ada
            $dokumenPath = null;
            if ($request->hasFile('dokumen')) {
                try {
                    $file = $request->file('dokumen');
                    $userId = Auth::id();
                    $timestamp = time();
                    $originalName = $file->getClientOriginalName();
                    $filename = $timestamp . '_' . $originalName;
                    $filePath = "dokumen_peminjaman/{$userId}/{$filename}";

                    // Siapkan data untuk upload ke Supabase
                    $fileContent = file_get_contents($file);

                    // Baca langsung dari .env file (bypass cache)
                    $envPath = base_path('.env');
                    $envContent = file_get_contents($envPath);

                    // Parse .env
                    preg_match('/SUPABASE_URL=(.*)/', $envContent, $urlMatch);
                    preg_match('/SUPABASE_SERVICE_ROLE=(.*)/', $envContent, $keyMatch);
                    preg_match('/SUPABASE_BUCKET=(.*)/', $envContent, $bucketMatch);

                    $supabaseUrl = trim($urlMatch[1] ?? '');
                    $supabaseKey = trim($keyMatch[1] ?? '');
                    $supabaseBucket = trim($bucketMatch[1] ?? '');

                    // Validasi environment variables
                    if (!$supabaseUrl || !$supabaseBucket || !$supabaseKey) {
                        throw new \Exception('Supabase configuration missing: URL=' . ($supabaseUrl ? 'OK' : 'MISSING') .
                            ', BUCKET=' . ($supabaseBucket ? 'OK' : 'MISSING') .
                            ', KEY=' . ($supabaseKey ? 'OK' : 'MISSING'));
                    }

                    // Construct URL dengan benar
                    $uploadUrl = "{$supabaseUrl}/storage/v1/object/{$supabaseBucket}/{$filePath}";

                    Log::info('Supabase upload attempt', [
                        'url' => $uploadUrl,
                        'file' => $filename,
                        'path' => $filePath,
                        'bucket' => $supabaseBucket,
                        'key_length' => strlen($supabaseKey),
                        'key_preview' => substr($supabaseKey, 0, 30) . '...' . substr($supabaseKey, -10),
                    ]);

                    // Upload ke Supabase Storage via REST API
                    // Menggunakan service role key untuk bypass row-level security
                    $response = Http::timeout(60)
                        ->withHeaders([
                            'Authorization' => 'Bearer ' . $supabaseKey,
                            'Content-Type' => $file->getMimeType(),
                        ])->withBody($fileContent, $file->getMimeType())
                        ->post($uploadUrl);

                    Log::info('Supabase upload response', [
                        'status' => $response->status(),
                        'success' => $response->successful(),
                        'body' => $response->body(),
                    ]);

                    if ($response->failed()) {
                        throw new \Exception('Gagal upload file ke Supabase: ' . $response->body());
                    }

                    // Generate public URL dari Supabase
                    $dokumenPath = "{$supabaseUrl}/storage/v1/object/public/{$supabaseBucket}/{$filePath}";

                } catch (\Exception $e) {
                    Log::error('Supabase upload error', [
                        'error' => $e->getMessage(),
                        'user_id' => Auth::id(),
                    ]);
                    throw $e;
                }
            }

            // Insert ke tabel peminjaman (1 record = 1 booking)
            $peminjamanId = DB::table('peminjaman')->insertGetId([
                'userid'     => $userId,
                'ruanganid'  => $request->ruangan_id,
                'tanggal'    => $request->tanggal,
                'nama_shift' => $namaShift,
                'keterangan' => $request->keterangan,
                'dokumen'    => $dokumenPath,
            ], 'peminjamanid');


            // Insert status awal ke tabel riwayat_status
            DB::table('riwayat_status')->insert([
                'peminjamanid' => $peminjamanId,
                'nama_status'  => 'Menunggu',
                'waktu_update' => now(),
                'keterangan'   => 'Pengajuan peminjaman ruangan baru',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Peminjaman berhasil diajukan',
                'data' => [
                    'peminjaman_id' => $peminjamanId,
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            if (!empty($dokumenPath) && Storage::disk('public')->exists($dokumenPath)) {
                Storage::disk('public')->delete($dokumenPath);
            }

            Log::error('Peminjaman store error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memproses peminjaman',
                'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function checkAvailability(Request $request)
    {
        if (!Auth::check()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'ruangan_id' => 'required|integer|exists:ruangan,ruanganid',
            'tanggal'    => 'required|date',
            'waktu'      => 'required|string|in:sesi1,sesi2,sesi3,sesi4',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $shiftMapping = [
            'sesi1' => 'Sesi 1 (07.00 - 10.00)',
            'sesi2' => 'Sesi 2 (10.15 - 12.45)',
            'sesi3' => 'Sesi 3 (13.30 - 16.00)',
            'sesi4' => 'Sesi 4 (16.00 - 18.30)',
        ];

        $namaShift = $shiftMapping[$request->waktu];

        $available = !DB::table('peminjaman as p')
            ->join('riwayat_status as rs', function ($join) {
                $join->on('p.peminjamanid', '=', 'rs.peminjamanid')
                    ->whereRaw('rs.statusid = (
                        SELECT MAX(statusid)
                        FROM riwayat_status
                        WHERE peminjamanid = p.peminjamanid
                    )');
            })
            ->where('p.ruanganid', $request->ruangan_id)
            ->where('p.tanggal', $request->tanggal)
            ->where('p.nama_shift', $namaShift)
            ->whereIn('rs.nama_status', ['Menunggu', 'Disetujui'])
            ->exists();

        return response()->json([
            'success'   => true,
            'available' => $available,
        ]);
    }

    public function getAvailableSlots(Request $request)
    {
        // Validasi input
        $validator = Validator::make($request->all(), [
            'ruangan_id' => 'required|integer|exists:ruangan,ruanganid',
            'tanggal'    => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Mapping sesi → label shift (sama seperti di store())
        $shiftMapping = [
            'sesi1' => 'Sesi 1 (07.00 - 10.00)',
            'sesi2' => 'Sesi 2 (10.15 - 12.45)',
            'sesi3' => 'Sesi 3 (13.30 - 16.00)',
            'sesi4' => 'Sesi 4 (16.00 - 18.30)',
        ];

        // Ambil semua shift yang sudah dibooking untuk ruangan + tanggal itu
        $bookedShifts = DB::table('peminjaman as p')
            ->join('riwayat_status as rs', function ($join) {
                $join->on('p.peminjamanid', '=', 'rs.peminjamanid')
                    ->whereRaw('rs.statusid = (
                        SELECT MAX(statusid)
                        FROM riwayat_status
                        WHERE peminjamanid = p.peminjamanid
                    )');
            })
            ->where('p.ruanganid', $request->ruangan_id)
            ->where('p.tanggal', $request->tanggal)
            ->whereIn('rs.nama_status', ['Menunggu', 'Disetujui'])
            ->pluck('p.nama_shift')
            ->toArray();

        // Bangun array slot: true = tersedia, false = penuh
        $slots = [];
        foreach ($shiftMapping as $key => $label) {
            $slots[$key] = !in_array($label, $bookedShifts);
        }

        return response()->json([
            'success' => true,
            'slots'   => $slots,
        ]);
    }
}
