<?php
session_start();
require_once __DIR__ . '/config.php';

$page = $_GET['page'] ?? 'iklan';
$action = $_POST['action'] ?? $_GET['action'] ?? null;
$message = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

class Database
{
    public function __construct(private PDO $koneksiDatabase) {}

    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->koneksiDatabase->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function one(string $sql, array $params = []): mixed
    {
        $stmt = $this->koneksiDatabase->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    public function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->koneksiDatabase->prepare($sql);
        return $stmt->execute($params);
    }

    public function rawQuery(string $sql): PDOStatement|false
    {
        return $this->koneksiDatabase->query($sql);
    }

    public function rawExec(string $sql): int|false
    {
        return $this->koneksiDatabase->exec($sql);
    }

    public function beginTransaction(): bool
    {
        return $this->koneksiDatabase->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->koneksiDatabase->commit();
    }

    public function rollBack(): bool
    {
        return $this->koneksiDatabase->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->koneksiDatabase->inTransaction();
    }

    public function lastInsertId(): string|false
    {
        return $this->koneksiDatabase->lastInsertId();
    }
}

class MigrasiSkemaDatabase
{
    public function __construct(private Database $database) {}

    public function ensurePaymentDueDateColumn(): void
    {
        $column = $this->database->rawQuery("SHOW COLUMNS FROM pembayaran LIKE 'tanggal_jatuh_tempo'")->fetch();
        if ($column) {
            return;
        }

        $this->database->rawExec('ALTER TABLE pembayaran ADD COLUMN tanggal_jatuh_tempo DATE AFTER tanggal_bayar');
        $this->database->rawExec(
            "UPDATE pembayaran
            SET tanggal_jatuh_tempo = STR_TO_DATE(
                CONCAT(
                    tahun,
                    '-',
                    CASE bulan
                        WHEN 'Januari' THEN '01'
                        WHEN 'Februari' THEN '02'
                        WHEN 'Maret' THEN '03'
                        WHEN 'April' THEN '04'
                        WHEN 'Mei' THEN '05'
                        WHEN 'Juni' THEN '06'
                        WHEN 'Juli' THEN '07'
                        WHEN 'Agustus' THEN '08'
                        WHEN 'September' THEN '09'
                        WHEN 'Oktober' THEN '10'
                        WHEN 'November' THEN '11'
                        WHEN 'Desember' THEN '12'
                        ELSE '01'
                    END,
                    '-10'
                ),
                '%Y-%m-%d'
            )
            WHERE tanggal_jatuh_tempo IS NULL"
        );
    }
}

class LayananAutentikasi
{
    public function __construct(private Database $database) {}

    public function requireLogin(): void
    {
        if (empty($_SESSION['user'])) {
            header('Location: index.php?page=login');
            exit;
        }
    }

    public function currentUser(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public function isAdmin(): bool
    {
        return ($this->currentUser()['role'] ?? '') === 'admin';
    }

    public function currentPenyewa(): ?array
    {
        $user = $this->currentUser();
        if (!$user || ($user['role'] ?? '') !== 'penyewa') {
            return null;
        }

        $penyewa = $this->database->one(
            'SELECT p.*, k.nomor_kamar, k.harga_sewa FROM penyewa p JOIN kamar k ON k.id_kamar = p.id_kamar WHERE p.id_user = ? AND p.status_penyewa = "Aktif"',
            [$user['id_user']]
        );

        return $penyewa ?: null;
    }

    public function requireAdmin(): void
    {
        if (!$this->isAdmin()) {
            redirect_to('dashboard');
        }
    }
}

class LayananTagihan
{
    public function __construct(private Database $database) {}

    public function recalculateInstallmentStatus(int $idPenyewa, string $bulan, int|string $tahun): void
    {
        $data = $this->database->one(
            'SELECT k.harga_sewa FROM penyewa p JOIN kamar k ON k.id_kamar = p.id_kamar WHERE p.id_penyewa = ?',
            [$idPenyewa]
        );
        if (!$data) {
            return;
        }

        $total = $this->database->one(
            "SELECT COALESCE(SUM(jumlah_bayar), 0) total FROM pembayaran WHERE id_penyewa = ? AND bulan = ? AND tahun = ? AND status_bayar IN ('Lunas', 'Belum Lunas')",
            [$idPenyewa, $bulan, $tahun]
        );
        $status = ((float) $total['total'] >= (float) $data['harga_sewa']) ? 'Lunas' : 'Belum Lunas';

        $this->database->execute(
            "UPDATE pembayaran SET status_bayar = ? WHERE id_penyewa = ? AND bulan = ? AND tahun = ? AND status_bayar IN ('Lunas', 'Belum Lunas')",
            [$status, $idPenyewa, $bulan, $tahun]
        );
    }

    public function remainingBalance(int $idPenyewa, string $bulan, int|string $tahun): float
    {
        $data = $this->database->one(
            'SELECT k.harga_sewa FROM penyewa p JOIN kamar k ON k.id_kamar = p.id_kamar WHERE p.id_penyewa = ?',
            [$idPenyewa]
        );
        if (!$data) {
            return 0;
        }

        $total = $this->database->one(
            "SELECT COALESCE(SUM(jumlah_bayar), 0) total FROM pembayaran WHERE id_penyewa = ? AND bulan = ? AND tahun = ? AND status_bayar IN ('Lunas', 'Belum Lunas')",
            [$idPenyewa, $bulan, $tahun]
        );

        return max(0, (float) $data['harga_sewa'] - (float) $total['total']);
    }

    public function dueDate(string $bulan, int|string $tahun): string
    {
        $bulanMap = [
            'Januari' => 1,
            'Februari' => 2,
            'Maret' => 3,
            'April' => 4,
            'Mei' => 5,
            'Juni' => 6,
            'Juli' => 7,
            'Agustus' => 8,
            'September' => 9,
            'Oktober' => 10,
            'November' => 11,
            'Desember' => 12,
        ];
        $month = $bulanMap[$bulan] ?? (int) date('n');
        return sprintf('%04d-%02d-10', (int) $tahun, $month);
    }

    public function billStatus(float $sisa, ?string $jatuhTempo, float $menunggu = 0): string
    {
        if ($sisa <= 0) {
            return 'Lunas';
        }
        if ((float) $menunggu > 0) {
            return 'Menunggu Verifikasi';
        }
        if ($jatuhTempo && date('Y-m-d') > $jatuhTempo) {
            return 'Terlambat';
        }
        return 'Belum Lunas';
    }

    public function dueDateSqlExpression(): string
    {
        return "COALESCE(MIN(b.tanggal_jatuh_tempo), STR_TO_DATE(CONCAT(b.tahun, '-', CASE b.bulan WHEN 'Januari' THEN '01' WHEN 'Februari' THEN '02' WHEN 'Maret' THEN '03' WHEN 'April' THEN '04' WHEN 'Mei' THEN '05' WHEN 'Juni' THEN '06' WHEN 'Juli' THEN '07' WHEN 'Agustus' THEN '08' WHEN 'September' THEN '09' WHEN 'Oktober' THEN '10' WHEN 'November' THEN '11' WHEN 'Desember' THEN '12' ELSE '01' END, '-10'), '%Y-%m-%d'))";
    }
}

class LayananUpload
{
    public function __construct(private string $uploadDir, private string $publicDir) {}

    public function buktiTransfer(string $field): string
    {
        if (empty($_FILES[$field]['name'])) {
            throw new RuntimeException('Foto bukti transfer wajib diunggah.');
        }

        if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload bukti transfer gagal.');
        }

        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $extension = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowed, true)) {
            throw new RuntimeException('Format bukti harus JPG, JPEG, PNG, atau WEBP.');
        }

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
        }

        $filename = 'bukti-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $target = $this->uploadDir . '/' . $filename;
        if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
            throw new RuntimeException('Bukti transfer tidak dapat disimpan.');
        }

        return $this->publicDir . '/' . $filename;
    }
}

class User
{
    public $id_user;
    public $nama;
    public $username;
    public $password;
    public $role;
    public $created_at;
}

class Kamar
{
    public $id_kamar;
    public $nomor_kamar;
    public $tipe_kamar;
    public $harga_sewa;
    public $fasilitas;
    public $status_kamar;
    public $keterangan;
}

class Penyewa
{
    public $id_penyewa;
    public $id_user;
    public $id_kamar;
    public $nama_penyewa;
    public $no_identitas;
    public $no_hp;
    public $alamat_asal;
    public $pekerjaan;
    public $tanggal_masuk;
    public $tanggal_keluar;
    public $status_penyewa;
}

class Pembayaran
{
    public $id_pembayaran;
    public $kode_pembayaran;
    public $id_penyewa;
    public $id_kamar;
    public $bulan;
    public $tahun;
    public $tanggal_bayar;
    public $tanggal_jatuh_tempo;
    public $jumlah_bayar;
    public $metode_bayar;
    public $status_bayar;
    public $keterangan;
    public $bukti_transfer;
}

class Device
{
    public $id_device;
    public $id_kamar;
    public $nama_device;
    public $jenis_device;
    public $status_device;
    public $mode_kontrol;
    public $updated_at;
}

class JenisPerangkat
{
    public $id_jenis;
    public $nama_jenis;
    public $keterangan;
}

$database = new Database($pdo);
$migrasiSkemaDatabase = new MigrasiSkemaDatabase($database);
$layananAutentikasi = new LayananAutentikasi($database);
$layananTagihan = new LayananTagihan($database);
$layananUpload = new LayananUpload(__DIR__ . '/uploads/bukti', 'uploads/bukti');

function ensure_database_schema()
{
    global $migrasiSkemaDatabase;
    $migrasiSkemaDatabase->ensurePaymentDueDateColumn();
}

ensure_database_schema();

function set_message($message, $type = 'success')
{
    $_SESSION['flash'] = [
        'text' => $message,
        'type' => $type,
    ];
}

function query_all($sql, $params = [])
{
    global $database;
    return $database->all($sql, $params);
}

function query_one($sql, $params = [])
{
    global $database;
    return $database->one($sql, $params);
}

function execute_sql($sql, $params = [])
{
    global $database;
    return $database->execute($sql, $params);
}

function hitung_status_cicilan($idPenyewa, $bulan, $tahun)
{
    global $layananTagihan;
    $layananTagihan->recalculateInstallmentStatus((int) $idPenyewa, $bulan, $tahun);
}

function sisa_tagihan($idPenyewa, $bulan, $tahun)
{
    global $layananTagihan;
    return $layananTagihan->remainingBalance((int) $idPenyewa, $bulan, $tahun);
}

function jatuh_tempo($bulan, $tahun)
{
    global $layananTagihan;
    return $layananTagihan->dueDate($bulan, $tahun);
}

function status_tagihan($sisa, $jatuhTempo, $menunggu = 0)
{
    global $layananTagihan;
    return $layananTagihan->billStatus((float) $sisa, $jatuhTempo, (float) $menunggu);
}

function sql_jatuh_tempo_expr()
{
    global $layananTagihan;
    return $layananTagihan->dueDateSqlExpression();
}

function require_login()
{
    global $layananAutentikasi;
    $layananAutentikasi->requireLogin();
}

function current_user()
{
    global $layananAutentikasi;
    return $layananAutentikasi->currentUser();
}

function is_admin()
{
    global $layananAutentikasi;
    return $layananAutentikasi->isAdmin();
}

function current_penyewa()
{
    global $layananAutentikasi;
    return $layananAutentikasi->currentPenyewa();
}

function require_admin()
{
    global $layananAutentikasi;
    $layananAutentikasi->requireAdmin();
}

function upload_bukti_transfer($field)
{
    global $layananUpload;
    return $layananUpload->buktiTransfer($field);
}

if ($page === 'logout') {
    session_destroy();
    header('Location: index.php?page=login');
    exit;
}

if ($page === 'login' && $action === 'login') {
    $user = query_one(
        'SELECT * FROM users WHERE username = ? AND password = ?',
        [$_POST['username'] ?? '', $_POST['password'] ?? '']
    );
    if ($user) {
        $_SESSION['user'] = $user;
        redirect_to('dashboard');
    }
    $message = [
        'text' => 'Username atau password salah.',
        'type' => 'danger',
    ];
}

if (!in_array($page, ['login', 'iklan'], true)) {
    require_login();
}

try {
    if ($action === 'save_user') {
        require_admin();

        $idPenyewa = (int) ($_POST['id_penyewa'] ?? 0);
        if ($idPenyewa <= 0) {
            throw new RuntimeException('Pilih data penyewa terlebih dahulu.');
        }

        $penyewa = query_one(
            "SELECT * FROM penyewa WHERE id_penyewa = ? AND status_penyewa = 'Aktif'",
            [$idPenyewa]
        );
        if (!$penyewa) {
            throw new RuntimeException('Data penyewa aktif tidak ditemukan.');
        }
        if (!empty($penyewa['id_user'])) {
            throw new RuntimeException('Penyewa ini sudah memiliki akun login.');
        }

        $usernameDipakai = query_one('SELECT COUNT(*) total FROM users WHERE username = ?', [$_POST['username']]);
        if ((int) $usernameDipakai['total'] > 0) {
            throw new RuntimeException('Username sudah digunakan. Silakan gunakan username lain.');
        }

        $database->beginTransaction();
        execute_sql(
            "INSERT INTO users (nama, username, password, role) VALUES (?, ?, ?, 'penyewa')",
            [$penyewa['nama_penyewa'], $_POST['username'], $_POST['password']]
        );
        $idUser = $database->lastInsertId();
        execute_sql('UPDATE penyewa SET id_user = ? WHERE id_penyewa = ?', [$idUser, $idPenyewa]);
        $database->commit();

        set_message('Akun login penyewa berhasil ditambahkan dan terhubung ke data penyewa.');
        redirect_to('users');
    }

    if ($action === 'update_password') {
        require_admin();
        execute_sql(
            'UPDATE users SET password = ? WHERE id_user = ?',
            [$_POST['password'], $_POST['id_user']]
        );
        set_message('Password user berhasil diperbarui.');
        redirect_to('users');
    }

    if ($action === 'delete_user') {
        require_admin();
        if ((int) ($_GET['id'] ?? 0) === (int) current_user()['id_user']) {
            throw new RuntimeException('User yang sedang login tidak dapat dihapus.');
        }
        $database->beginTransaction();
        execute_sql('UPDATE penyewa SET id_user = NULL WHERE id_user = ?', [$_GET['id']]);
        execute_sql('DELETE FROM users WHERE id_user = ?', [$_GET['id']]);
        $database->commit();
        set_message('User berhasil dihapus.');
        redirect_to('users');
    }

    if ($action === 'save_kamar') {
        require_admin();
        execute_sql(
            'INSERT INTO kamar (nomor_kamar, tipe_kamar, harga_sewa, fasilitas, status_kamar, keterangan) VALUES (?, ?, ?, ?, ?, ?)',
            [$_POST['nomor_kamar'], $_POST['tipe_kamar'], $_POST['harga_sewa'], $_POST['fasilitas'], $_POST['status_kamar'], $_POST['keterangan']]
        );
        set_message('Kamar berhasil ditambahkan.');
        redirect_to('kamar');
    }

    if ($action === 'delete_kamar') {
        require_admin();
        $kamar = query_one('SELECT * FROM kamar WHERE id_kamar = ?', [$_GET['id']]);
        if (!$kamar) {
            throw new RuntimeException('Data kamar tidak ditemukan.');
        }
        if ($kamar['status_kamar'] === 'Terisi') {
            throw new RuntimeException('Kamar yang masih terisi tidak dapat dihapus.');
        }
        $penyewaAktif = query_one(
            "SELECT COUNT(*) total FROM penyewa WHERE id_kamar = ? AND status_penyewa = 'Aktif'",
            [$_GET['id']]
        );
        if ((int) $penyewaAktif['total'] > 0) {
            throw new RuntimeException('Kamar masih memiliki penyewa aktif dan tidak dapat dihapus.');
        }
        $database->beginTransaction();
        execute_sql('DELETE FROM pembayaran WHERE id_kamar = ?', [$_GET['id']]);
        execute_sql('DELETE FROM penyewa WHERE id_kamar = ?', [$_GET['id']]);
        execute_sql('DELETE FROM devices WHERE id_kamar = ?', [$_GET['id']]);
        execute_sql('DELETE FROM kamar WHERE id_kamar = ?', [$_GET['id']]);
        $database->commit();
        set_message('Kamar kosong beserta riwayat penyewa, pembayaran, dan device terkait berhasil dihapus.');
        redirect_to('kamar');
    }

    if ($action === 'selesai_perbaikan_kamar') {
        require_admin();
        $kamar = query_one('SELECT * FROM kamar WHERE id_kamar = ?', [$_GET['id']]);
        if (!$kamar) {
            throw new RuntimeException('Data kamar tidak ditemukan.');
        }
        if ($kamar['status_kamar'] !== 'Perbaikan') {
            throw new RuntimeException('Hanya kamar berstatus perbaikan yang dapat diselesaikan.');
        }
        execute_sql("UPDATE kamar SET status_kamar = 'Kosong' WHERE id_kamar = ?", [$_GET['id']]);
        set_message('Perbaikan kamar selesai. Status kamar berubah menjadi kosong.');
        redirect_to('kamar');
    }

    if ($action === 'save_penyewa') {
        require_admin();
        $database->beginTransaction();
        $idUser = null;
        if (!empty($_POST['username']) && !empty($_POST['password'])) {
            execute_sql(
                "INSERT INTO users (nama, username, password, role) VALUES (?, ?, ?, 'penyewa')",
                [$_POST['nama_penyewa'], $_POST['username'], $_POST['password']]
            );
            $idUser = $database->lastInsertId();
        }
        execute_sql(
            "INSERT INTO penyewa (id_user, id_kamar, nama_penyewa, no_identitas, no_hp, alamat_asal, pekerjaan, tanggal_masuk, status_penyewa) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Aktif')",
            [$idUser, $_POST['id_kamar'], $_POST['nama_penyewa'], $_POST['no_identitas'], $_POST['no_hp'], $_POST['alamat_asal'], $_POST['pekerjaan'], $_POST['tanggal_masuk']]
        );
        execute_sql("UPDATE kamar SET status_kamar = 'Terisi' WHERE id_kamar = ?", [$_POST['id_kamar']]);
        $database->commit();
        set_message('Penyewa berhasil ditambahkan dan kamar berubah menjadi terisi.');
        redirect_to('penyewa');
    }

    if ($action === 'penyewa_keluar') {
        require_admin();
        $penyewa = query_one('SELECT * FROM penyewa WHERE id_penyewa = ?', [$_GET['id']]);
        if ($penyewa) {
            $database->beginTransaction();
            execute_sql("UPDATE penyewa SET status_penyewa = 'Keluar', tanggal_keluar = CURDATE() WHERE id_penyewa = ?", [$penyewa['id_penyewa']]);
            execute_sql("UPDATE kamar SET status_kamar = 'Kosong' WHERE id_kamar = ?", [$penyewa['id_kamar']]);
            $database->commit();
        }
        set_message('Penyewa keluar, status kamar kembali kosong.');
        redirect_to('penyewa');
    }

    if ($action === 'delete_penyewa') {
        require_admin();
        $penyewa = query_one('SELECT * FROM penyewa WHERE id_penyewa = ?', [$_GET['id']]);
        if (!$penyewa) {
            throw new RuntimeException('Data penyewa tidak ditemukan.');
        }
        $database->beginTransaction();
        execute_sql('DELETE FROM pembayaran WHERE id_penyewa = ?', [$penyewa['id_penyewa']]);
        execute_sql('DELETE FROM penyewa WHERE id_penyewa = ?', [$penyewa['id_penyewa']]);
        if (!empty($penyewa['id_user'])) {
            execute_sql('DELETE FROM users WHERE id_user = ?', [$penyewa['id_user']]);
        }
        if ($penyewa['status_penyewa'] === 'Aktif') {
            execute_sql("UPDATE kamar SET status_kamar = 'Kosong' WHERE id_kamar = ?", [$penyewa['id_kamar']]);
        }
        $database->commit();
        set_message('Data penyewa, pembayaran terkait, dan akun login penyewa berhasil dihapus.');
        redirect_to('penyewa');
    }

    if ($action === 'save_pembayaran') {
        require_admin();
        $data = query_one(
            'SELECT p.id_penyewa, p.id_kamar, k.harga_sewa FROM penyewa p JOIN kamar k ON k.id_kamar = p.id_kamar WHERE p.id_penyewa = ?',
            [$_POST['id_penyewa']]
        );
        $status = ((float) $_POST['jumlah_bayar'] >= (float) $data['harga_sewa']) ? 'Lunas' : 'Belum Lunas';
        execute_sql(
            'INSERT INTO pembayaran (kode_pembayaran, id_penyewa, id_kamar, bulan, tahun, tanggal_bayar, tanggal_jatuh_tempo, jumlah_bayar, metode_bayar, status_bayar, keterangan) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$_POST['kode_pembayaran'], $data['id_penyewa'], $data['id_kamar'], $_POST['bulan'], $_POST['tahun'], $_POST['tanggal_bayar'], jatuh_tempo($_POST['bulan'], $_POST['tahun']), $_POST['jumlah_bayar'], 'Tunai', $status, $_POST['keterangan']]
        );
        hitung_status_cicilan($data['id_penyewa'], $_POST['bulan'], $_POST['tahun']);
        set_message('Pembayaran berhasil disimpan.');
        redirect_to('pembayaran');
    }

    if ($action === 'validasi_pembayaran') {
        require_admin();
        $status = $_GET['status'] ?? '';
        if (!in_array($status, ['Valid', 'Ditolak'], true)) {
            throw new RuntimeException('Status validasi tidak valid.');
        }
        $pembayaran = query_one('SELECT * FROM pembayaran WHERE id_pembayaran = ?', [$_GET['id']]);
        if (!$pembayaran) {
            throw new RuntimeException('Data pembayaran tidak ditemukan.');
        }
        $statusBaru = $status === 'Ditolak' ? 'Ditolak' : 'Belum Lunas';
        execute_sql(
            'UPDATE pembayaran SET status_bayar = ?, keterangan = CONCAT(COALESCE(keterangan, ""), ?) WHERE id_pembayaran = ?',
            [$statusBaru, $status === 'Valid' ? ' | Diverifikasi admin' : ' | Ditolak admin', $_GET['id']]
        );
        if ($status === 'Valid') {
            hitung_status_cicilan($pembayaran['id_penyewa'], $pembayaran['bulan'], $pembayaran['tahun']);
        }
        set_message(
            $status === 'Valid' ? 'Pembayaran berhasil divalidasi dan status cicilan dihitung ulang.' : 'Pembayaran ditolak.',
            $status === 'Valid' ? 'success' : 'warning'
        );
        redirect_to('pembayaran');
    }

    if ($action === 'hitung_ulang_cicilan') {
        require_admin();
        $periode = query_all(
            "SELECT DISTINCT id_penyewa, bulan, tahun FROM pembayaran WHERE status_bayar IN ('Lunas', 'Belum Lunas')"
        );
        foreach ($periode as $row) {
            hitung_status_cicilan($row['id_penyewa'], $row['bulan'], $row['tahun']);
        }
        set_message('Status cicilan semua pembayaran berhasil dihitung ulang.');
        redirect_to('pembayaran');
    }

    if ($action === 'bayar_sisa') {
        require_admin();
        $pembayaran = query_one('SELECT * FROM pembayaran WHERE id_pembayaran = ?', [$_POST['id_pembayaran']]);
        if (!$pembayaran) {
            throw new RuntimeException('Data pembayaran tidak ditemukan.');
        }

        $sisa = sisa_tagihan($pembayaran['id_penyewa'], $pembayaran['bulan'], $pembayaran['tahun']);
        if ($sisa <= 0) {
            hitung_status_cicilan($pembayaran['id_penyewa'], $pembayaran['bulan'], $pembayaran['tahun']);
            set_message('Pembayaran periode ini sudah lunas.', 'warning');
            redirect_to('pembayaran');
        }

        $jumlahBayar = (float) ($_POST['jumlah_bayar'] ?? 0);
        if ($jumlahBayar <= 0) {
            throw new RuntimeException('Jumlah pembayaran sisa harus lebih dari 0.');
        }
        if ($jumlahBayar > $sisa) {
            throw new RuntimeException('Jumlah pembayaran sisa tidak boleh melebihi ' . rupiah($sisa) . '.');
        }

        execute_sql(
            "INSERT INTO pembayaran (kode_pembayaran, id_penyewa, id_kamar, bulan, tahun, tanggal_bayar, tanggal_jatuh_tempo, jumlah_bayar, metode_bayar, status_bayar, keterangan) VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, 'Tunai', 'Belum Lunas', 'Pelunasan sisa tagihan oleh admin')",
            ['CIC-' . time(), $pembayaran['id_penyewa'], $pembayaran['id_kamar'], $pembayaran['bulan'], $pembayaran['tahun'], jatuh_tempo($pembayaran['bulan'], $pembayaran['tahun']), $jumlahBayar]
        );
        hitung_status_cicilan($pembayaran['id_penyewa'], $pembayaran['bulan'], $pembayaran['tahun']);
        set_message('Pembayaran cicilan berhasil ditambahkan sebesar ' . rupiah($jumlahBayar) . '.');
        redirect_to('pembayaran');
    }

    if ($action === 'save_device') {
        require_admin();
        execute_sql(
            'INSERT INTO devices (id_kamar, nama_device, jenis_device, status_device, mode_kontrol) VALUES (?, ?, ?, ?, ?)',
            [$_POST['id_kamar'], $_POST['nama_device'], $_POST['jenis_device'], 'Mati', 'Remote']
        );
        set_message('Device berhasil ditambahkan.');
        redirect_to('device');
    }

    if ($action === 'save_jenis_perangkat') {
        require_admin();
        execute_sql(
            'INSERT INTO jenis_perangkat (nama_jenis, keterangan) VALUES (?, ?)',
            [$_POST['nama_jenis'], $_POST['keterangan']]
        );
        set_message('Jenis perangkat berhasil ditambahkan.');
        redirect_to('device');
    }

    if ($action === 'delete_jenis_perangkat') {
        require_admin();
        $jenis = query_one('SELECT * FROM jenis_perangkat WHERE id_jenis = ?', [$_GET['id']]);
        if (!$jenis) {
            throw new RuntimeException('Jenis perangkat tidak ditemukan.');
        }
        $database->beginTransaction();
        execute_sql('DELETE FROM devices WHERE jenis_device = ?', [$jenis['nama_jenis']]);
        execute_sql('DELETE FROM jenis_perangkat WHERE id_jenis = ?', [$_GET['id']]);
        $database->commit();
        set_message('Jenis perangkat dan device terkait berhasil dihapus.');
        redirect_to('device');
    }

    if ($action === 'toggle_device') {
        if (!is_admin()) {
            $penyewa = current_penyewa();
            $device = query_one(
                "SELECT * FROM devices WHERE id_device = ? AND id_kamar = ?",
                [$_GET['id'], $penyewa['id_kamar'] ?? 0]
            );
            if (!$device) {
                throw new RuntimeException('Penyewa hanya dapat mengontrol device pada kamar sendiri.');
            }
        }
        execute_sql('UPDATE devices SET status_device = ? WHERE id_device = ?', [$_GET['status'], $_GET['id']]);
        set_message('Status device berhasil diperbarui.');
        redirect_to(is_admin() ? 'device' : 'led_saya');
    }

    if ($action === 'upload_pembayaran_penyewa') {
        $penyewa = current_penyewa();
        if (!$penyewa) {
            throw new RuntimeException('Data penyewa aktif tidak ditemukan.');
        }
        $bukti = upload_bukti_transfer('bukti_transfer');
        execute_sql(
            "INSERT INTO pembayaran (kode_pembayaran, id_penyewa, id_kamar, bulan, tahun, tanggal_bayar, tanggal_jatuh_tempo, jumlah_bayar, metode_bayar, status_bayar, keterangan, bukti_transfer) VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, 'Transfer bank', 'Menunggu Verifikasi', ?, ?)",
            ['BYR-' . time(), $penyewa['id_penyewa'], $penyewa['id_kamar'], $_POST['bulan'], $_POST['tahun'], jatuh_tempo($_POST['bulan'], $_POST['tahun']), $_POST['jumlah_bayar'], $_POST['keterangan'], $bukti]
        );
        set_message('Bukti pembayaran berhasil dikirim dan menunggu verifikasi admin.');
        redirect_to('pembayaran_saya');
    }
} catch (Throwable $e) {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    $message = [
        'text' => 'Terjadi kesalahan: ' . $e->getMessage(),
        'type' => 'danger',
    ];
}

function render_header($title)
{
    global $page, $message;
    $menus = is_admin()
        ? [
            'dashboard' => 'Dashboard',
            'kamar' => 'Kamar',
            'penyewa' => 'Penyewa',
            'users' => 'User & Password',
            'pembayaran' => 'Pembayaran',
            'device' => 'Device',
            'laporan' => 'Laporan',
        ]
        : [
            'dashboard' => 'Dashboard',
            'kamar_kosong' => 'Kamar Kosong',
            'pembayaran_saya' => 'Pembayaran Saya',
            'led_saya' => 'Kontrol Device',
        ];
?>

    <!doctype html>
    <html lang="id">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
        <link rel="stylesheet" href="style.css?v=20260607-mobile">
    </head>

    <body class="bg-body-tertiary">
        <?php if (!empty($_SESSION['user'])): ?>
            <nav class="navbar navbar-expand-lg navbar-dark app-navbar sticky-top">
                <div class="container-fluid px-3 px-lg-4">
                    <a class="navbar-brand d-flex align-items-center gap-2" href="index.php?page=dashboard">
                        <div class="login-brand">
                            <span class="brand-mark">
                                <img src="image/ICON LOGO.png" alt="Logo SIMKOS">
                            </span>
                            <span>
                                <strong class="brand-title">SIMKOS</strong>
                                <small class="brand-subtitle">Manajemen sewa, kamar, dan device</small>
                            </span>
                        </div>
                    </a>
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse" id="mainNav">
                        <div class="navbar-nav ms-lg-4 gap-lg-1">
                            <?php foreach ($menus as $key => $label): ?>
                                <a class="nav-link <?= $page === $key ? 'active' : '' ?>" href="index.php?page=<?= e($key) ?>"><?= e($label) ?></a>
                            <?php endforeach; ?>
                        </div>
                        <div class="ms-lg-auto mt-3 mt-lg-0 d-flex align-items-lg-center gap-2 flex-column flex-lg-row">
                            <span class="badge rounded-pill text-bg-light"><i class="bi bi-person-circle me-1"></i><?= e(current_user()['nama'] ?? 'User') ?> · <?= e(current_user()['role'] ?? '-') ?></span>
                            <a class="btn btn-outline-light btn-sm" href="index.php?page=logout"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
                        </div>
                    </div>
                </div>
            </nav>
        <?php else: ?>
            <header class="login-topbar">
                <div class="login-brand">
                    <span class="brand-mark">
                        <img src="image/ICON LOGO.png" alt="Logo SIMKOS">
                    </span>
                    <span>
                        <strong class="brand-title">SIMKOS</strong>
                        <small class="brand-subtitle">Manajemen sewa, kamar, dan device</small>
                    </span>
                </div>
            </header>
        <?php endif; ?>
        <main class="container-fluid app-container">
            <?php if ($message): ?>
                <?php
                $alertType = $message['type'] ?? 'warning';
                $alertIcon = [
                    'success' => 'bi-check-circle-fill',
                    'warning' => 'bi-exclamation-triangle-fill',
                    'danger' => 'bi-x-circle-fill',
                ][$alertType] ?? 'bi-info-circle-fill';
                ?>
                <div class="alert alert-<?= e($alertType) ?> shadow-sm d-flex align-items-center gap-2">
                    <i class="bi <?= e($alertIcon) ?>"></i>
                    <span><?= e($message['text'] ?? '') ?></span>
                </div>
            <?php endif; ?>
        <?php
    }

    function render_contact_footer()
    {
        $email = 'info@sistemkosweb.com';
        $wa = '6281234567890';
        $waLabel = '+62 812-3456-7890';
        $mapUrl = 'https://www.google.com/maps/search/?api=1&query=kos';
        ?>
            <footer class="site-footer">
                <div class="site-footer-main">
                    <div class="site-footer-brand">
                        <span class="brand-mark">
                            <img src="image/ICON LOGO.png" alt="Logo Kos">
                        </span>
                        <div>
                            <strong>SIMKOS</strong>
                            <p>Informasi kontak kos, bantuan pembayaran, lokasi, dan sosial media.</p>
                        </div>
                    </div>
                    <div class="footer-contact-grid">
                        <a href="mailto:<?= e($email) ?>">
                            <i class="bi bi-envelope-fill"></i>
                            <span>Email</span>
                            <strong><?= e($email) ?></strong>
                        </a>
                        <a href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener">
                            <i class="bi bi-whatsapp"></i>
                            <span>WhatsApp</span>
                            <strong><?= e($waLabel) ?></strong>
                        </a>
                        <a href="<?= e($mapUrl) ?>" target="_blank" rel="noopener">
                            <i class="bi bi-geo-alt-fill"></i>
                            <span>Map</span>
                            <strong>Lihat lokasi kos</strong>
                        </a>
                        <div class="footer-social-card">
                            <i class="bi bi-share-fill"></i>
                            <span>Sosial Media</span>
                            <div class="footer-social-links">
                                <a href="https://instagram.com/" target="_blank" rel="noopener" aria-label="Instagram"><i class="bi bi-instagram"></i></a>
                                <a href="https://facebook.com/" target="_blank" rel="noopener" aria-label="Facebook"><i class="bi bi-facebook"></i></a>
                                <a href="https://tiktok.com/" target="_blank" rel="noopener" aria-label="TikTok"><i class="bi bi-tiktok"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="site-footer-bottom">
                    <span>&copy;2026 Ilmu Komputer (Universitas Nahdlatul Ulama Blitar)</span>
                </div>
            </footer>
        <?php
    }

    function render_footer()
    {
        ?>
        </main>
        <?php render_contact_footer(); ?>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>

    </html>
<?php
    }

    function render_column_guide($items)
    {
?>
    <!-- <div class="column-guide">
            <strong><i class="bi bi-info-circle me-1"></i>Keterangan kolom</strong>
            <div class="column-guide-grid">
                <?php foreach ($items as $label => $description): ?>
                    <div><span><?= e($label) ?></span><?= e($description) ?></div>
                <?php endforeach; ?>
            </div>
        </div> -->
<?php
    }
    if ($page === 'login') {
        render_header('Login Sistem Kos');
?>

    <section class="login-card card shadow border-0">
        <div class="login-card-layout">
            <div class="login-card-logo">
                <img src="image/ICON LOGO.png" alt="Logo SIMKOS">
            </div>
            <div class="login-card-content">
                <h1 class="h4 mb-1">Login Pengguna</h1>
                <p class="text-secondary mb-4">Masuk sebagai admin atau penyewa kos.</p>
                <form method="post">
                    <input type="hidden" name="action" value="login">
                    <label class="form-label">Username</label>
                    <input class="form-control" name="username" value="" required>
                    <label class="form-label">Password</label>
                    <input class="form-control" name="password" type="password" value="" required>
                    <button class="btn btn-primary w-100 mt-2" type="submit"><i class="bi bi-box-arrow-in-right me-1"></i>Masuk</button>
                    <a class="btn btn-outline-secondary w-100 mt-2" href="index.php?page=iklan"><i class="bi bi-arrow-left-circle me-1"></i>Kembali ke Halaman Iklan</a>
                </form>
            </div>
        </div>
    </section>
<?php
        render_footer();
        exit;
    }

    if ($page === 'iklan') {
        $kamarIklan = query_all("SELECT * FROM kamar WHERE status_kamar = 'Kosong' ORDER BY harga_sewa, nomor_kamar LIMIT 6");
        $hargaMulai = query_one("SELECT MIN(harga_sewa) harga FROM kamar WHERE status_kamar = 'Kosong'")['harga'] ?? 0;
        $waPemilikKos = '6281234567890';
        $gambarKamarList = [
            'https://images.unsplash.com/photo-1618221195710-dd6b41faaea6?auto=format&fit=crop&w=900&q=80',
            'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?auto=format&fit=crop&w=900&q=80',
            'https://images.unsplash.com/photo-1595526114035-0d45ed16cfbf?auto=format&fit=crop&w=900&q=80',
            'https://images.unsplash.com/photo-1616594039964-ae9021a400a0?auto=format&fit=crop&w=900&q=80',
            'https://images.unsplash.com/photo-1616486338812-3dadae4b4ace?auto=format&fit=crop&w=900&q=80',
            'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?auto=format&fit=crop&w=900&q=80',
        ];
?>
    <!doctype html>
    <html lang="id">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Iklan Kos - SIMKOS</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
        <link rel="stylesheet" href="style.css?v=20260607-mobile">
    </head>

    <body class="ad-page">

        <!-- --------------------------------------------------------------------BEGIN HEADER WEB------------------------------------------------------------------->
        <nav class="ad-nav">
            <a class="ad-brand" href="index.php">
                <span class="brand-mark">
                    <img src="image/ICON LOGO.png" alt="Logo Kos">
                </span>
                <span><span class="brand-title">SIMKOS</span><small class="brand-subtitle">Kos nyaman, pembayaran mudah, dilengkapi dengan sistem IoT</small></span>
            </a>
            <a class="btn btn-light" href="index.php?page=login"><i class="bi bi-box-arrow-in-right me-1"></i>Login</a>
        </nav>
        <!-- --------------------------------------------------------------------END HEADER WEB------------------------------------------------------------------->

        <main>
            <!-- --------------------------------------------------------------------BEGIN SECTION WEB SITE IKLAN------------------------------------------------->
            <section class="ad-hero">
                <div class="ad-hero-content">
                    <h1>Kos nyaman dengan kontrol fasilitas dan pembayaran digital</h1>
                    <p>Cek kamar kosong, kelola pembayaran sewa, upload bukti transfer, dan kontrol device kamar dalam satu sistem praktis.</p>
                    <?php $pesanBookingUmum = rawurlencode('Halo Admin Kos, saya ingin booking kamar kos. Apakah masih ada kamar kosong yang tersedia?'); ?>
                    <div class="ad-actions">
                        <a class="btn btn-primary btn-lg" href="index.php?page=login"><i class="bi bi-box-arrow-in-right me-1"></i>Login</a>
                        <a class="btn btn-success btn-lg" href="https://wa.me/<?= e($waPemilikKos) ?>?text=<?= e($pesanBookingUmum) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp me-1"></i>Booking Kamar</a>
                        <a class="btn btn-warning btn-lg" href="#kamar"><i class="bi bi-door-open me-1"></i>Lihat Kamar</a>
                    </div>
                    <div class="ad-stats">
                        <div><strong><?= e(count($kamarIklan)) ?></strong><span>Kamar tersedia</span></div>
                        <div><strong><?= $hargaMulai ? rupiah($hargaMulai) : '-' ?></strong><span>Harga mulai</span></div>
                        <div><strong>24 Jam</strong><span>Akses sistem</span></div>
                    </div>
                </div>
            </section>
            <section class="ad-section" id="kamar">
                <div class="ad-section-head">
                    <span>Kamar Kosong</span>
                    <h2>Pilih kamar yang sesuai kebutuhanmu</h2>
                </div>
                <div class="ad-room-grid">
                    <?php foreach ($kamarIklan as $row): ?>
                        <?php
                        $pesanBooking = rawurlencode(
                            'Halo Admin Kos, saya ingin booking Kamar ' . $row['nomor_kamar'] .
                                ' tipe ' . $row['tipe_kamar'] .
                                ' dengan harga ' . rupiah($row['harga_sewa']) . '/bulan. Apakah kamar ini masih tersedia?'
                        );
                        $gambarIndex = abs(crc32((string) $row['nomor_kamar'])) % count($gambarKamarList);
                        $gambarKamar = $gambarKamarList[$gambarIndex];
                        ?>
                        <article class="ad-room-card">
                            <img class="ad-room-photo" src="<?= e($gambarKamar) ?>" alt="Foto kamar <?= e($row['nomor_kamar']) ?>" loading="lazy" onerror="this.onerror=null;this.src='https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?auto=format&fit=crop&w=900&q=80';">
                            <div class="ad-room-icon"><i class="bi bi-house-door"></i></div>
                            <h3>Kamar <?= e($row['nomor_kamar']) ?></h3>
                            <p><?= e($row['tipe_kamar']) ?> · <?= rupiah($row['harga_sewa']) ?>/bulan</p>
                            <span><?= e($row['fasilitas'] ?: 'Fasilitas standar kos') ?></span>
                            <a class="ad-booking-btn" href="https://wa.me/<?= e($waPemilikKos) ?>?text=<?= e($pesanBooking) ?>" target="_blank" rel="noopener">
                                <i class="bi bi-whatsapp me-1"></i>Booking Kamar
                            </a>
                        </article>
                    <?php endforeach; ?>
                    <?php if (!$kamarIklan): ?>
                        <article class="ad-room-card">
                            <div class="ad-room-icon"><i class="bi bi-info-circle"></i></div>
                            <h3>Kamar penuh</h3>
                            <p>Belum ada kamar kosong saat ini.</p>
                            <span>Silakan login atau hubungi admin kos untuk info terbaru.</span>
                        </article>
                    <?php endif; ?>
                </div>
            </section>

            <section class="ad-section">
                <div class="ad-section-head">
                    <span>Beberapa tampilan kamar dan fasilitas kos yang tersedia.</span>
                    <h2>Galeri Kamar Kos</h2>
                </div>
                <div class="ad-room-grid">
                    <div class="ad-room-grid">
                        <div class="kamar-card">
                            <img src="https://images.unsplash.com/photo-1618221195710-dd6b41faaea6?auto=format&fit=crop&w=800&q=80" alt="Kamar Kos 1">
                            <h3>Kamar Tipe A</h3>
                        </div>

                        <div class="kamar-card">
                            <img src="https://images.unsplash.com/photo-1631049307264-da0ec9d70304?auto=format&fit=crop&w=800&q=80" alt="Kamar Kos 2">
                            <h3>Kamar Tipe B</h3>
                        </div>

                        <div class="kamar-card">
                            <img src="https://images.unsplash.com/photo-1560185127-6ed189bf02f4?auto=format&fit=crop&w=800&q=80" alt="Kamar Kos 3">
                            <h3>Kamar Tipe C</h3>
                        </div>

                        <div class="kamar-card">
                            <img src="https://images.unsplash.com/photo-1595526114035-0d45ed16cfbf?auto=format&fit=crop&w=800&q=80" alt="Kamar Kos 4">
                            <h3>Kamar Nyaman</h3>
                        </div>

                        <div class="kamar-card">
                            <img src="https://images.unsplash.com/photo-1616594039964-ae9021a400a0?auto=format&fit=crop&w=800&q=80" alt="Kamar Kos 5">
                            <h3>Kamar Lengkap</h3>
                        </div>

                        <div class="kamar-card">
                            <img src="https://images.unsplash.com/photo-1616486338812-3dadae4b4ace?auto=format&fit=crop&w=800&q=80" alt="Kamar Kos 6">
                            <h3>Kamar Minimalis</h3>
                        </div>

                        <div class="kamar-card">
                            <img src="https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?auto=format&fit=crop&w=800&q=80" alt="Kamar Kos 7">
                            <h3>Kamar Premium</h3>
                        </div>

                        <div class="kamar-card">
                            <img src="https://images.unsplash.com/photo-1540518614846-7eded433c457?auto=format&fit=crop&w=800&q=80" alt="Kamar Kos 8">
                            <h3>Kamar Eksklusif</h3>
                        </div>

                        <div class="kamar-card">
                            <img src="https://images.unsplash.com/photo-1586023492125-27b2c045efd7?auto=format&fit=crop&w=800&q=80" alt="Kamar Kos 9">
                            <h3>Kamar Furnished</h3>
                        </div>

                        <div class="kamar-card">
                            <img src="https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?auto=format&fit=crop&w=800&q=80" alt="Kamar Kos 10">
                            <h3>Fasilitas Kos</h3>
                        </div>
                    </div>
                </div>
            </section>

            <section class="ad-section ad-feature-band">
                <div class="ad-section-head">
                    <span>Fitur</span>
                    <h2>Sewa kos lebih tertata</h2>
                </div>
                <div class="ad-feature-grid">
                    <div><i class="bi bi-receipt"></i><strong>Pembayaran Transfer</strong>
                        <p>Upload bukti pembayaran dan tunggu validasi admin.</p>
                    </div>
                    <div><i class="bi bi-lightbulb"></i><strong>Kontrol Device</strong>
                        <p>Penyewa dapat mengontrol device pada kamar sendiri.</p>
                    </div>
                    <div><i class="bi bi-calendar-check"></i><strong>Jatuh Tempo</strong>
                        <p>Tagihan memiliki tenggat pembayaran yang jelas.</p>
                    </div>
                </div>
            </section>

            <section class="ad-section">
                <div class="ad-section-head">
                    <span>Fitur</span>
                    <h2>Sewa kos lebih tertata</h2>
                </div>
                <div class="ad-feature-grid">
                    <div><i class="bi bi-receipt"></i><strong>Pembayaran Transfer</strong>
                        <p>Upload bukti pembayaran dan tunggu validasi admin.</p>
                    </div>
                    <div><i class="bi bi-lightbulb"></i><strong>Kontrol Device</strong>
                        <p>Penyewa dapat mengontrol device pada kamar sendiri.</p>
                    </div>
                    <div><i class="bi bi-calendar-check"></i><strong>Jatuh Tempo</strong>
                        <p>Tagihan memiliki tenggat pembayaran yang jelas.</p>
                    </div>
                </div>
            </section>
            <!-- --------------------------------------------------------------------END SECTION WEB SITE IKLAN------------------------------------------------->
            <?php render_contact_footer(); ?>
        </main>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>

    </html>
    <?php
        exit;
    }

    render_header('SIMKOS');

    if ($page === 'dashboard') {
        if (!is_admin()) {
            $penyewa = current_penyewa();
            $kamarKosong = query_one("SELECT COUNT(*) total FROM kamar WHERE status_kamar = 'Kosong'")['total'];
            $deviceMenyala = $penyewa ? query_one("SELECT COUNT(*) total FROM devices WHERE id_kamar = ? AND status_device = 'Menyala'", [$penyewa['id_kamar']])['total'] : 0;
            $deviceMati = $penyewa ? query_one("SELECT COUNT(*) total FROM devices WHERE id_kamar = ? AND status_device = 'Mati'", [$penyewa['id_kamar']])['total'] : 0;
    ?>
        <section class="cards">
            <article class="card"><span>Kamar Saya</span><strong><?= e($penyewa['nomor_kamar'] ?? '-') ?></strong></article>
            <article class="card"><span>Sewa Bulanan</span><strong><?= isset($penyewa['harga_sewa']) ? rupiah($penyewa['harga_sewa']) : '-' ?></strong></article>
            <article class="card"><span>Kamar Kosong</span><strong><?= e($kamarKosong) ?></strong></article>
            <article class="card"><span>Device Menyala</span><strong><?= e($deviceMenyala) ?></strong></article>
        </section>
        <section class="chart-grid">
            <div class="panel chart-panel">
                <h2>Status Device Kamar</h2>
                <canvas id="tenantDeviceChart"></canvas>
            </div>
        </section>
        <script>
            new Chart(document.getElementById('tenantDeviceChart'), {
                type: 'doughnut',
                data: {
                    labels: ['Menyala', 'Mati'],
                    datasets: [{
                        data: [<?= (int) $deviceMenyala ?>, <?= (int) $deviceMati ?>],
                        backgroundColor: ['#22a2b8', '#dce5ef']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });
        </script>
    <?php
        } else {
            $stats = [
                'Total Kamar' => query_one('SELECT COUNT(*) total FROM kamar')['total'],
                'Kamar Terisi' => query_one("SELECT COUNT(*) total FROM kamar WHERE status_kamar = 'Terisi'")['total'],
                'Kamar Kosong' => query_one("SELECT COUNT(*) total FROM kamar WHERE status_kamar = 'Kosong'")['total'],
                'Device Menyala' => query_one("SELECT COUNT(*) total FROM devices WHERE status_device = 'Menyala'")['total'],
                'Pemasukan Bulan Ini' => rupiah(query_one('SELECT COALESCE(SUM(jumlah_bayar), 0) total FROM pembayaran WHERE MONTH(tanggal_bayar)=MONTH(CURDATE()) AND YEAR(tanggal_bayar)=YEAR(CURDATE())')['total']),
            ];
            $kamarChart = [
                (int) query_one("SELECT COUNT(*) total FROM kamar WHERE status_kamar = 'Terisi'")['total'],
                (int) query_one("SELECT COUNT(*) total FROM kamar WHERE status_kamar = 'Kosong'")['total'],
                (int) query_one("SELECT COUNT(*) total FROM kamar WHERE status_kamar = 'Perbaikan'")['total'],
            ];
            $deviceChart = [
                (int) query_one("SELECT COUNT(*) total FROM devices WHERE status_device = 'Menyala'")['total'],
                (int) query_one("SELECT COUNT(*) total FROM devices WHERE status_device = 'Mati'")['total'],
            ];
            $bulanList = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
            $pemasukanRows = query_all(
                "SELECT bulan, SUM(jumlah_bayar) total FROM pembayaran WHERE tahun = ? AND status_bayar IN ('Lunas', 'Belum Lunas') GROUP BY bulan",
                [date('Y')]
            );
            $pemasukanMap = [];
            foreach ($pemasukanRows as $row) {
                $pemasukanMap[$row['bulan']] = (float) $row['total'];
            }
            $pemasukanChart = array_map(fn($bulan) => $pemasukanMap[$bulan] ?? 0, $bulanList);
            $latest = query_all('SELECT b.*, p.nama_penyewa, k.nomor_kamar FROM pembayaran b JOIN penyewa p ON p.id_penyewa=b.id_penyewa JOIN kamar k ON k.id_kamar=b.id_kamar ORDER BY b.id_pembayaran DESC LIMIT 8');
    ?>
        <section class="cards">
            <?php foreach ($stats as $label => $value): ?>
                <article class="card"><span><?= e($label) ?></span><strong><?= e($value) ?></strong></article>
            <?php endforeach; ?>
        </section>
        <section class="chart-grid">
            <div class="panel chart-panel">
                <h2>Status Kamar</h2>
                <canvas id="roomStatusChart"></canvas>
            </div>
            <div class="panel chart-panel">
                <h2>Pemasukan <?= e(date('Y')) ?></h2>
                <canvas id="incomeChart"></canvas>
            </div>
            <div class="panel chart-panel">
                <h2>Status Device</h2>
                <canvas id="deviceStatusChart"></canvas>
            </div>
        </section>
        <script>
            new Chart(document.getElementById('roomStatusChart'), {
                type: 'doughnut',
                data: {
                    labels: ['Terisi', 'Kosong', 'Perbaikan'],
                    datasets: [{
                        data: <?= json_encode($kamarChart) ?>,
                        backgroundColor: ['#146c7c', '#22a2b8', '#f4b740']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });
            new Chart(document.getElementById('incomeChart'), {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode($bulanList) ?>,
                    datasets: [{
                        label: 'Pemasukan',
                        data: <?= json_encode($pemasukanChart) ?>,
                        backgroundColor: ['#146c7c', '#22a2b8', '#8fb9c5', '#f4b740', '#6f8ea6', '#d36c4a', '#7c6bb0', '#9bbf30', '#d98bb3', '#5f9ea0', '#c0a16b', '#6c7a89']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });
            new Chart(document.getElementById('deviceStatusChart'), {
                type: 'pie',
                data: {
                    labels: ['Menyala', 'Mati'],
                    datasets: [{
                        data: <?= json_encode($deviceChart) ?>,
                        backgroundColor: ['#22a2b8', '#dce5ef']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });
        </script>
        <section class="panel">
            <h2>Pembayaran Terbaru</h2>
            <?php render_column_guide([
                'Kode' => 'Nomor unik transaksi pembayaran.',
                'Penyewa' => 'Nama penyewa yang membayar.',
                'Kamar' => 'Nomor kamar penyewa.',
                'Bulan' => 'Periode bulan pembayaran.',
                'Jumlah' => 'Nominal pembayaran yang masuk.',
                'Status' => 'Kondisi pembayaran terbaru.'
            ]); ?>
            <?php render_table($latest, ['kode_pembayaran' => 'Kode', 'nama_penyewa' => 'Penyewa', 'nomor_kamar' => 'Kamar', 'bulan' => 'Bulan', 'jumlah_bayar' => 'Jumlah', 'status_bayar' => 'Status'], true); ?>
        </section>
    <?php
        }
    }

    if ($page === 'kamar') {
        require_admin();
        $kamar = query_all('SELECT * FROM kamar ORDER BY nomor_kamar');
    ?>
    <section class="panel">
        <h2>Tambah Kamar</h2>
        <?php render_column_guide([
            'Nomor kamar' => 'Kode atau nomor unik kamar kos, contoh 056 atau A01.',
            'Tipe' => 'Jenis kamar, misalnya Standar, VIP, atau Eksklusif.',
            'Harga sewa' => 'Biaya sewa kamar per bulan.',
            'Status' => 'Kondisi awal kamar: kosong atau sedang perbaikan.',
            'Fasilitas' => 'Daftar fasilitas kamar seperti kasur, lemari, AC, kipas, atau kamar mandi.',
            'Keterangan' => 'Catatan tambahan tentang kamar.'
        ]); ?>
        <form class="grid-form" method="post">
            <input type="hidden" name="action" value="save_kamar">
            <div class="form-field">
                <label>Nomor kamar</label>
                <input name="nomor_kamar" placeholder="Contoh: A01 atau 056" required>
            </div>
            <div class="form-field">
                <label>Tipe kamar</label>
                <input name="tipe_kamar" placeholder="Tipe kamar" value="Standar" required>
            </div>
            <div class="form-field">
                <label>Harga sewa</label>
                <input name="harga_sewa" placeholder="Harga sewa" type="number" value="600000" required>
            </div>
            <div class="form-field">
                <label>Status kamar</label>
                <select name="status_kamar">
                    <option>Kosong</option>
                    <option>Perbaikan</option>
                </select>
            </div>
            <div class="form-field">
                <label>Fasilitas</label>
                <input name="fasilitas" placeholder="Contoh: kasur, lemari, kipas">
            </div>
            <div class="form-field">
                <label>Keterangan</label>
                <input name="keterangan" placeholder="Catatan tambahan">
            </div>
            <button type="submit"><i class="bi bi-save me-1"></i>Simpan Kamar</button>
        </form>
    </section>
    <section class="panel">
        <h2>Data Kamar</h2>
        <?php render_column_guide([
            'Kamar' => 'Nomor kamar yang terdaftar.',
            'Tipe' => 'Kategori kamar.',
            'Harga' => 'Harga sewa bulanan.',
            'Status' => 'Kosong, Terisi, atau Perbaikan.',
            'Fasilitas' => 'Fasilitas yang dimiliki kamar.',
            'Aksi' => 'Tombol untuk menghapus atau menyelesaikan perbaikan kamar.'
        ]); ?>
        <?php render_table($kamar, ['nomor_kamar' => 'Kamar', 'tipe_kamar' => 'Tipe', 'harga_sewa' => 'Harga', 'status_kamar' => 'Status', 'fasilitas' => 'Fasilitas'], true, function ($row) {
            $actions = [];
            if ($row['status_kamar'] === 'Perbaikan') {
                $actions[] = '<a class="btn btn-sm btn-success" href="index.php?page=kamar&action=selesai_perbaikan_kamar&id=' . e($row['id_kamar']) . '" onclick="return confirm(\'Tandai perbaikan kamar ini sudah selesai?\')"><i class="bi bi-check2-circle me-1"></i>Selesai</a>';
            }
            $actions[] = '<a class="btn btn-sm btn-outline-danger" href="index.php?page=kamar&action=delete_kamar&id=' . e($row['id_kamar']) . '" onclick="return confirm(\'Hapus kamar kosong ini?\')"><i class="bi bi-trash me-1"></i>Hapus</a>';
            return '<div class="d-flex flex-wrap gap-2">' . implode('', $actions) . '</div>';
        }); ?>
    </section>
<?php
    }

    if ($page === 'penyewa') {
        require_admin();
        $kamarKosong = query_all("SELECT * FROM kamar WHERE status_kamar = 'Kosong' ORDER BY nomor_kamar");
        $penyewa = query_all('SELECT p.*, k.nomor_kamar FROM penyewa p JOIN kamar k ON k.id_kamar=p.id_kamar ORDER BY p.id_penyewa DESC');
?>
    <section class="panel">
        <h2>Tambah Penyewa</h2>
        <?php render_column_guide([
            'Nama penyewa' => 'Nama lengkap penyewa kos.',
            'No identitas' => 'Nomor KTP/Kartu identitas penyewa.',
            'No HP' => 'Nomor telepon aktif penyewa.',
            'Pekerjaan/status' => 'Status penyewa, misalnya mahasiswa, karyawan, atau umum.',
            'Username & password' => 'Akun login penyewa untuk mengakses halaman user.',
            'Alamat asal' => 'Alamat asal penyewa.',
            'Tanggal masuk' => 'Tanggal penyewa mulai menempati kamar.',
            'Kamar kosong' => 'Kamar yang akan ditempati penyewa.'
        ]); ?>
        <form class="grid-form" method="post">
            <input type="hidden" name="action" value="save_penyewa">
            <div class="form-field">
                <label>Nama penyewa</label>
                <input name="nama_penyewa" placeholder="Nama lengkap penyewa" required>
            </div>
            <div class="form-field">
                <label>No identitas</label>
                <input name="no_identitas" placeholder="NIK/KTP/Kartu identitas">
            </div>
            <div class="form-field">
                <label>No HP</label>
                <input name="no_hp" placeholder="Nomor WhatsApp/telepon">
            </div>
            <div class="form-field">
                <label>Pekerjaan/status</label>
                <input name="pekerjaan" placeholder="Mahasiswa, karyawan, dll.">
            </div>
            <div class="form-field">
                <label>Username login penyewa</label>
                <input name="username" placeholder="Username untuk akun penyewa">
            </div>
            <div class="form-field">
                <label>Password login penyewa</label>
                <input name="password" placeholder="Password untuk akun penyewa">
            </div>
            <div class="form-field">
                <label>Alamat asal</label>
                <input name="alamat_asal" placeholder="Alamat asal penyewa">
            </div>
            <div class="form-field">
                <label>Tanggal masuk</label>
                <input name="tanggal_masuk" type="date" value="<?= e(date('Y-m-d')) ?>" required>
            </div>
            <div class="form-field">
                <label>Pilih kamar kosong</label>
                <select name="id_kamar" required>
                    <option value="">Pilih kamar kosong</option>
                    <?php foreach ($kamarKosong as $row): ?>
                        <option value="<?= e($row['id_kamar']) ?>"><?= e($row['nomor_kamar']) ?> - <?= rupiah($row['harga_sewa']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit"><i class="bi bi-person-plus me-1"></i>Simpan Penyewa</button>
        </form>
    </section>
    <section class="panel">
        <h2>Data Penyewa</h2>
        <?php render_column_guide([
            'Nama' => 'Nama penyewa.',
            'Kamar' => 'Kamar yang ditempati.',
            'HP' => 'Nomor telepon penyewa.',
            'Masuk' => 'Tanggal mulai tinggal.',
            'Keluar' => 'Tanggal keluar jika sudah tidak aktif.',
            'Status' => 'Aktif atau Keluar.',
            'Aksi' => 'Tombol keluar atau hapus data penyewa.'
        ]); ?>
        <?php render_table($penyewa, ['nama_penyewa' => 'Nama', 'nomor_kamar' => 'Kamar', 'no_hp' => 'HP', 'tanggal_masuk' => 'Masuk', 'tanggal_keluar' => 'Keluar', 'status_penyewa' => 'Status'], false, function ($row) {
            $hapus = '<a class="btn btn-sm btn-outline-danger" href="index.php?page=penyewa&action=delete_penyewa&id=' . e($row['id_penyewa']) . '" onclick="return confirm(\'Hapus data penyewa ini beserta pembayaran terkait?\')"><i class="bi bi-trash me-1"></i>Hapus</a>';
            if ($row['status_penyewa'] !== 'Aktif') {
                return $hapus;
            }
            return '<div class="d-flex flex-wrap gap-2"><a class="btn btn-sm btn-outline-warning" href="index.php?page=penyewa&action=penyewa_keluar&id=' . e($row['id_penyewa']) . '" onclick="return confirm(\'Tandai penyewa keluar?\')"><i class="bi bi-door-open me-1"></i>Keluar</a>' . $hapus . '</div>';
        }); ?>
    </section>
<?php
    }

    if ($page === 'users') {
        require_admin();
        $users = query_all('SELECT id_user, nama, username, role, created_at FROM users ORDER BY role, nama');
        $penyewaTanpaAkun = query_all(
            "SELECT p.id_penyewa, p.nama_penyewa, k.nomor_kamar
        FROM penyewa p
        JOIN kamar k ON k.id_kamar = p.id_kamar
        WHERE p.status_penyewa = 'Aktif' AND p.id_user IS NULL
        ORDER BY p.nama_penyewa"
        );
?>
    <section class="panel">
        <h2>Tambah User Penyewa</h2>
        <?php render_column_guide([
            'Pilih penyewa' => 'Data penyewa yang akan dibuatkan akun login.',
            'Username' => 'Nama pengguna untuk masuk ke sistem.',
            'Password' => 'Kata sandi awal untuk penyewa.'
        ]); ?>
        <form class="grid-form" method="post">
            <input type="hidden" name="action" value="save_user">
            <div class="form-field">
                <label>Pilih penyewa</label>
                <select name="id_penyewa" required>
                    <option value="">Pilih penyewa</option>
                    <?php foreach ($penyewaTanpaAkun as $row): ?>
                        <option value="<?= e($row['id_penyewa']) ?>"><?= e($row['nama_penyewa']) ?> - Kamar <?= e($row['nomor_kamar']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label>Username login penyewa</label>
                <input name="username" placeholder="Username login penyewa" required>
            </div>
            <div class="form-field">
                <label>Password login penyewa</label>
                <input name="password" placeholder="Password login penyewa" required>
            </div>
            <button type="submit" <?= empty($penyewaTanpaAkun) ? 'disabled' : '' ?>><i class="bi bi-person-add me-1"></i>Tambah User</button>
        </form>
        <?php if (empty($penyewaTanpaAkun)): ?>
            <p class="text-secondary mt-3 mb-0">Semua penyewa aktif sudah memiliki akun login.</p>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Data User dan Password</h2>
        <?php render_column_guide([
            'Nama' => 'Nama pemilik akun.',
            'Username' => 'Nama login pengguna.',
            'Role' => 'Jenis akses: admin atau penyewa.',
            'Dibuat' => 'Waktu akun dibuat.',
            'Ganti Password' => 'Form untuk mengganti kata sandi.',
            'Aksi' => 'Tombol untuk menghapus akun.'
        ]); ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Dibuat</th>
                        <th>Ganti Password</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $row): ?>
                        <tr>
                            <td><?= e($row['nama']) ?></td>
                            <td><?= e($row['username']) ?></td>
                            <td><?= e($row['role']) ?></td>
                            <td><?= e($row['created_at']) ?></td>
                            <td>
                                <form class="inline-form" method="post">
                                    <input type="hidden" name="action" value="update_password">
                                    <input type="hidden" name="id_user" value="<?= e($row['id_user']) ?>">
                                    <div class="form-field inline-field">
                                        <label>Password baru</label>
                                        <input name="password" placeholder="Password baru" required>
                                    </div>
                                    <button type="submit"><i class="bi bi-key me-1"></i>Simpan</button>
                                </form>
                            </td>
                            <td>
                                <?php if ((int) $row['id_user'] !== (int) current_user()['id_user']): ?>
                                    <a class="btn btn-sm btn-outline-danger" href="index.php?page=users&action=delete_user&id=<?= e($row['id_user']) ?>" onclick="return confirm('Hapus user ini?')"><i class="bi bi-trash me-1"></i>Hapus</a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php
    }

    if ($page === 'pembayaran') {
        require_admin();
        $penyewaAktif = query_all("SELECT p.*, k.nomor_kamar, k.harga_sewa FROM penyewa p JOIN kamar k ON k.id_kamar=p.id_kamar WHERE p.status_penyewa='Aktif' ORDER BY p.nama_penyewa");
        $pembayaran = query_all('SELECT b.*, p.nama_penyewa, k.nomor_kamar FROM pembayaran b JOIN penyewa p ON p.id_penyewa=b.id_penyewa JOIN kamar k ON k.id_kamar=b.id_kamar ORDER BY b.id_pembayaran DESC');
        $jatuhTempoSql = sql_jatuh_tempo_expr();
        $tagihan = query_all(
            "SELECT
            MIN(b.id_pembayaran) AS id_pembayaran,
            b.id_penyewa,
            b.id_kamar,
            p.nama_penyewa,
            k.nomor_kamar,
            k.harga_sewa,
            b.bulan,
            b.tahun,
            {$jatuhTempoSql} AS tanggal_jatuh_tempo,
            SUM(CASE WHEN b.status_bayar IN ('Lunas', 'Belum Lunas') THEN b.jumlah_bayar ELSE 0 END) AS total_bayar,
            SUM(CASE WHEN b.status_bayar = 'Menunggu Verifikasi' THEN b.jumlah_bayar ELSE 0 END) AS menunggu_verifikasi,
            SUM(CASE WHEN b.status_bayar = 'Ditolak' THEN b.jumlah_bayar ELSE 0 END) AS ditolak
        FROM pembayaran b
        JOIN penyewa p ON p.id_penyewa = b.id_penyewa
        JOIN kamar k ON k.id_kamar = b.id_kamar
        GROUP BY b.id_penyewa, b.id_kamar, p.nama_penyewa, k.nomor_kamar, k.harga_sewa, b.bulan, b.tahun
        ORDER BY b.tahun DESC, MAX(b.id_pembayaran) DESC"
        );
        $bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
?>
    <section class="page-note">
        <div class="page-note-icon"><i class="bi bi-journal-text"></i></div>
        <div>
            <strong>Pencatatan Laporan Pembayaran</strong>
            <p>Halaman ini digunakan admin untuk mencatat pembayaran sewa, melihat ringkasan tagihan bulanan, memproses cicilan, serta memvalidasi bukti transfer dari penyewa. Jatuh tempo pembayaran otomatis ditetapkan setiap tanggal 10 pada bulan tagihan.</p>
        </div>
    </section>
    <section class="panel">
        <h2>Tambah Pembayaran</h2>
        <?php render_column_guide([
            'Kode pembayaran' => 'Nomor unik transaksi pembayaran yang dibuat sistem.',
            'Penyewa aktif' => 'Nama penyewa yang masih menempati kamar.',
            'Bulan dan tahun' => 'Periode tagihan sewa yang akan dicatat.',
            'Tanggal bayar' => 'Tanggal admin menerima pembayaran.',
            'Jumlah bayar' => 'Nominal uang yang dibayarkan penyewa.',
            'Keterangan' => 'Catatan tambahan, misalnya cicilan atau pembayaran penuh.'
        ]); ?>
        <form class="grid-form" method="post">
            <input type="hidden" name="action" value="save_pembayaran">
            <div class="form-field">
                <label>Kode pembayaran</label>
                <input name="kode_pembayaran" value="BYR-<?= e(time()) ?>" required>
            </div>
            <div class="form-field">
                <label>Pilih penyewa aktif</label>
                <select name="id_penyewa" required>
                    <option value="">Pilih penyewa aktif</option>
                    <?php foreach ($penyewaAktif as $row): ?>
                        <option value="<?= e($row['id_penyewa']) ?>"><?= e($row['nama_penyewa']) ?> - <?= e($row['nomor_kamar']) ?> - <?= rupiah($row['harga_sewa']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label>Bulan tagihan</label>
                <select name="bulan"><?php foreach ($bulan as $b): ?><option><?= e($b) ?></option><?php endforeach; ?></select>
            </div>
            <div class="form-field">
                <label>Tahun tagihan</label>
                <input name="tahun" type="number" value="<?= e(date('Y')) ?>" required>
            </div>
            <div class="form-field">
                <label>Tanggal bayar</label>
                <input name="tanggal_bayar" type="date" value="<?= e(date('Y-m-d')) ?>" required>
            </div>
            <div class="form-field">
                <label>Jumlah bayar</label>
                <input name="jumlah_bayar" type="number" placeholder="Jumlah bayar" required>
            </div>
            <div class="form-field">
                <label>Keterangan</label>
                <input name="keterangan" placeholder="Keterangan pembayaran">
            </div>
            <button type="submit"><i class="bi bi-cash-coin me-1"></i>Simpan Pembayaran</button>
        </form>
    </section>
    <section class="panel">
        <h2>Ringkasan Tagihan</h2>
        <?php render_column_guide([
            'Penyewa' => 'Nama penyewa yang memiliki tagihan.',
            'Kamar' => 'Nomor kamar yang ditempati penyewa.',
            'Periode' => 'Bulan dan tahun tagihan sewa.',
            'Jatuh Tempo' => 'Batas akhir pembayaran sebelum dianggap terlambat.',
            'Tagihan' => 'Harga sewa kamar pada periode tersebut.',
            'Dibayar' => 'Total pembayaran yang sudah valid.',
            'Sisa' => 'Kekurangan pembayaran yang masih harus dilunasi.',
            'Status' => 'Kondisi tagihan, seperti lunas, belum lunas, atau terlambat.',
            'Aksi' => 'Tombol untuk mencatat pembayaran sisa tagihan.'
        ]); ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Penyewa</th>
                        <th>Kamar</th>
                        <th>Periode</th>
                        <th>Jatuh Tempo</th>
                        <th>Tagihan</th>
                        <th>Dibayar</th>
                        <th>Sisa</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tagihan as $row): ?>
                        <?php
                        $sisa = max(0, (float) $row['harga_sewa'] - (float) $row['total_bayar']);
                        $status = status_tagihan($sisa, $row['tanggal_jatuh_tempo'], $row['menunggu_verifikasi']);
                        ?>
                        <tr>
                            <td><?= e($row['nama_penyewa']) ?></td>
                            <td><?= e($row['nomor_kamar']) ?></td>
                            <td><?= e($row['bulan']) ?> <?= e($row['tahun']) ?></td>
                            <td><?= e($row['tanggal_jatuh_tempo']) ?></td>
                            <td><?= rupiah($row['harga_sewa']) ?></td>
                            <td><?= rupiah($row['total_bayar']) ?></td>
                            <td><?= rupiah($sisa) ?></td>
                            <td><?= e($status) ?></td>
                            <td>
                                <?php if ($sisa > 0): ?>
                                    <form class="inline-form" method="post">
                                        <input type="hidden" name="action" value="bayar_sisa">
                                        <input type="hidden" name="id_pembayaran" value="<?= e($row['id_pembayaran']) ?>">
                                        <div class="form-field inline-field">
                                            <label>Nominal bayar</label>
                                            <input name="jumlah_bayar" type="number" min="1" max="<?= e($sisa) ?>" placeholder="Nominal bayar" required>
                                        </div>
                                        <button type="submit"><i class="bi bi-wallet2 me-1"></i>Bayar Sisa</button>
                                    </form>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$tagihan): ?>
                        <tr>
                            <td colspan="9">Belum ada tagihan.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <section class="panel">
        <h2>Riwayat Transaksi</h2>
        <?php render_column_guide([
            'Kode' => 'Nomor unik transaksi pembayaran.',
            'Penyewa' => 'Nama penyewa yang melakukan pembayaran.',
            'Kamar' => 'Nomor kamar penyewa.',
            'Bulan dan Tahun' => 'Periode pembayaran sewa.',
            'Jatuh Tempo' => 'Tanggal batas akhir pembayaran periode tersebut.',
            'Jumlah' => 'Nominal pembayaran pada transaksi ini.',
            'Status' => 'Hasil proses pembayaran, misalnya menunggu verifikasi, lunas, ditolak, atau belum lunas.',
            'Bukti' => 'Foto bukti transfer dari penyewa.',
            'Aksi' => 'Tombol validasi atau penolakan bukti transfer.'
        ]); ?>
        <?php render_table($pembayaran, ['kode_pembayaran' => 'Kode', 'nama_penyewa' => 'Penyewa', 'nomor_kamar' => 'Kamar', 'bulan' => 'Bulan', 'tahun' => 'Tahun', 'tanggal_jatuh_tempo' => 'Jatuh Tempo', 'jumlah_bayar' => 'Jumlah', 'status_bayar' => 'Status', 'bukti_transfer' => 'Bukti'], true, function ($row) {
            $bukti = !empty($row['bukti_transfer'])
                ? '<a class="btn btn-sm btn-outline-secondary" href="' . e($row['bukti_transfer']) . '" target="_blank"><i class="bi bi-image me-1"></i>Lihat Bukti</a>'
                : '-';
            if ($row['status_bayar'] !== 'Menunggu Verifikasi') {
                return $bukti;
            }
            return $bukti
                . '<div class="d-flex flex-wrap gap-2 mt-2">'
                . '<a class="btn btn-sm btn-success" href="index.php?page=pembayaran&action=validasi_pembayaran&id=' . e($row['id_pembayaran']) . '&status=Valid" onclick="return confirm(\'Validasi bukti pembayaran ini?\')"><i class="bi bi-check2-circle me-1"></i>Validasi</a>'
                . '<a class="btn btn-sm btn-outline-danger" href="index.php?page=pembayaran&action=validasi_pembayaran&id=' . e($row['id_pembayaran']) . '&status=Ditolak" onclick="return confirm(\'Tolak bukti pembayaran ini?\')"><i class="bi bi-x-circle me-1"></i>Tolak</a>'
                . '</div>';
        }); ?>
    </section>
<?php
    }

    if ($page === 'device') {
        require_admin();
        $kamar = query_all('SELECT * FROM kamar ORDER BY nomor_kamar');
        $jenisPerangkat = query_all('SELECT * FROM jenis_perangkat ORDER BY nama_jenis');
        $devices = query_all('SELECT d.*, k.nomor_kamar FROM devices d JOIN kamar k ON k.id_kamar=d.id_kamar ORDER BY k.nomor_kamar, d.nama_device');
?>
    <section class="panel">
        <h2>Jenis Perangkat</h2>
        <?php render_column_guide([
            'Jenis' => 'Nama kategori perangkat yang bisa dipilih saat menambah device.',
            'Keterangan' => 'Penjelasan tambahan tentang jenis perangkat.',
            'Aksi' => 'Tombol untuk menghapus jenis perangkat beserta device terkait.'
        ]); ?>
        <form class="grid-form jenis-perangkat-form" method="post">
            <input type="hidden" name="action" value="save_jenis_perangkat">
            <div class="form-field">
                <label>Nama jenis perangkat</label>
                <input name="nama_jenis" placeholder="Nama jenis, contoh: CCTV" required>
            </div>
            <div class="form-field">
                <label>Keterangan jenis</label>
                <input name="keterangan" placeholder="Keterangan">
            </div>
            <button type="submit"><i class="bi bi-plus-circle me-1"></i>Tambah Jenis</button>
        </form>
        <?php render_table($jenisPerangkat, ['nama_jenis' => 'Jenis', 'keterangan' => 'Keterangan'], false, function ($row) {
            return '<a class="btn btn-sm btn-outline-danger" href="index.php?page=device&action=delete_jenis_perangkat&id=' . e($row['id_jenis']) . '" onclick="return confirm(\'Hapus jenis perangkat ini?\')"><i class="bi bi-trash me-1"></i>Hapus</a>';
        }); ?>
    </section>
    <section class="panel">
        <h2>Tambah Device</h2>
        <?php render_column_guide([
            'Nama device' => 'Nama perangkat, misalnya Node 01, Lampu Depan, atau Kipas A.',
            'Jenis perangkat' => 'Kategori device yang sudah dibuat admin.',
            'Kamar' => 'Kamar tempat device dipasang.',
            'Status awal' => 'Device baru otomatis disimpan dalam kondisi mati.'
        ]); ?>
        <form class="grid-form" method="post">
            <input type="hidden" name="action" value="save_device">
            <div class="form-field">
                <label>Nama device</label>
                <input name="nama_device" placeholder="Nama device" required>
            </div>
            <div class="form-field">
                <label>Jenis perangkat</label>
                <select name="jenis_device" required>
                    <option value="">Pilih jenis perangkat</option>
                    <?php foreach ($jenisPerangkat as $jenis): ?>
                        <option value="<?= e($jenis['nama_jenis']) ?>"><?= e($jenis['nama_jenis']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label>Pilih kamar</label>
                <select name="id_kamar" required>
                    <option value="">Pilih kamar</option>
                    <?php foreach ($kamar as $row): ?>
                        <option value="<?= e($row['id_kamar']) ?>"><?= e($row['nomor_kamar']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit"><i class="bi bi-cpu me-1"></i>Simpan Device</button>
        </form>
    </section>
    <section class="panel">
        <h2>Kontrol Device</h2>
        <?php render_column_guide([
            'Kamar' => 'Nomor kamar tempat device berada.',
            'Device' => 'Nama perangkat yang dikontrol.',
            'Jenis' => 'Kategori perangkat seperti lampu, kipas, LED, atau lainnya.',
            'Status' => 'Kondisi perangkat saat ini, menyala atau mati.',
            'Mode' => 'Cara kontrol perangkat, misalnya remote.',
            'Update' => 'Waktu terakhir status device berubah.',
            'Aksi' => 'Tombol untuk menyalakan atau mematikan device.'
        ]); ?>
        <?php render_table($devices, ['nomor_kamar' => 'Kamar', 'nama_device' => 'Device', 'jenis_device' => 'Jenis', 'status_device' => 'Status', 'mode_kontrol' => 'Mode', 'updated_at' => 'Update'], false, function ($row) {
            $target = $row['status_device'] === 'Menyala' ? 'Mati' : 'Menyala';
            $icon = $target === 'Menyala' ? 'bi-lightbulb-fill' : 'bi-power';
            return '<a class="btn btn-sm btn-primary" href="index.php?page=device&action=toggle_device&id=' . e($row['id_device']) . '&status=' . e($target) . '"><i class="bi ' . e($icon) . ' me-1"></i>' . e($target) . '</a>';
        }); ?>
    </section>
<?php
    }

    if ($page === 'laporan') {
        require_admin();
        $filterBulan = $_GET['bulan'] ?? '';
        $filterTahun = $_GET['tahun'] ?? date('Y');
        $jatuhTempoSql = sql_jatuh_tempo_expr();
        $sql = "SELECT
        p.nama_penyewa,
        k.nomor_kamar,
        k.harga_sewa,
        b.bulan,
        b.tahun,
        MIN(b.tanggal_bayar) AS tanggal_awal,
        MAX(b.tanggal_bayar) AS tanggal_akhir,
        {$jatuhTempoSql} AS tanggal_jatuh_tempo,
        COUNT(*) AS jumlah_transaksi,
        SUM(CASE WHEN b.status_bayar IN ('Lunas', 'Belum Lunas') THEN b.jumlah_bayar ELSE 0 END) AS total_bayar,
        SUM(CASE WHEN b.status_bayar = 'Menunggu Verifikasi' THEN b.jumlah_bayar ELSE 0 END) AS menunggu_verifikasi,
        SUM(CASE WHEN b.status_bayar = 'Ditolak' THEN b.jumlah_bayar ELSE 0 END) AS ditolak
        FROM pembayaran b
        JOIN penyewa p ON p.id_penyewa=b.id_penyewa
        JOIN kamar k ON k.id_kamar=b.id_kamar
        WHERE (? = '' OR b.bulan = ?) AND (? = '' OR b.tahun = ?)
        GROUP BY b.id_penyewa, b.id_kamar, p.nama_penyewa, k.nomor_kamar, k.harga_sewa, b.bulan, b.tahun
        ORDER BY b.tahun DESC, MAX(b.id_pembayaran) DESC";
        $rows = query_all($sql, [$filterBulan, $filterBulan, $filterTahun, $filterTahun]);
        $total = array_sum(array_map(fn($row) => (float) $row['total_bayar'], $rows));
        $laporanLabels = [];
        $laporanTagihan = [];
        $laporanDibayar = [];
        $laporanSisa = [];
        foreach ($rows as $row) {
            $laporanLabels[] = $row['nama_penyewa'] . ' - ' . $row['bulan'] . ' ' . $row['tahun'];
            $laporanTagihan[] = (float) $row['harga_sewa'];
            $laporanDibayar[] = (float) $row['total_bayar'];
            $laporanSisa[] = max(0, (float) $row['harga_sewa'] - (float) $row['total_bayar']);
        }
?>
    <section class="panel">
        <h2>Laporan Pembayaran</h2>
        <p class="form-description">Laporan ringkasan pembayaran berdasarkan bulan dan tahun, termasuk total pemasukan, sisa tagihan, dan status keterlambatan.</p>
        <?php render_column_guide([
            'Penyewa' => 'Nama penyewa yang masuk ke laporan.',
            'Kamar' => 'Nomor kamar penyewa.',
            'Bulan dan Tahun' => 'Periode laporan pembayaran.',
            'Jatuh Tempo' => 'Batas akhir pembayaran periode tersebut.',
            'Tagihan' => 'Harga sewa yang harus dibayar.',
            'Dibayar' => 'Total pembayaran yang sudah valid.',
            'Sisa' => 'Nominal kekurangan pembayaran.',
            'Status' => 'Kesimpulan kondisi tagihan pada periode laporan.'
        ]); ?>
        <form class="filter" method="get">
            <input type="hidden" name="page" value="laporan">
            <div class="form-field">
                <label>Filter bulan</label>
                <input name="bulan" placeholder="Bulan, contoh: Juni" value="<?= e($filterBulan) ?>">
            </div>
            <div class="form-field">
                <label>Filter tahun</label>
                <input name="tahun" placeholder="Tahun" value="<?= e($filterTahun) ?>">
            </div>
            <button type="submit"><i class="bi bi-funnel me-1"></i>Filter</button>
            <a class="btn btn-outline-secondary" href="index.php?page=laporan"><i class="bi bi-arrow-clockwise me-1"></i>Reset</a>
        </form>
        <div class="summary">Total pemasukan: <strong><?= rupiah($total) ?></strong></div>
        <div class="chart-grid single">
            <div class="panel chart-panel">
                <h2>Grafik Tagihan dan Pembayaran</h2>
                <canvas id="reportPaymentChart"></canvas>
            </div>
        </div>
        <script>
            new Chart(document.getElementById('reportPaymentChart'), {
                type: 'doughnut',
                data: {
                    labels: ['Tagihan', 'Dibayar', 'Sisa'],
                    datasets: [{
                        data: [
                            <?= json_encode(array_sum($laporanTagihan)) ?>,
                            <?= json_encode(array_sum($laporanDibayar)) ?>,
                            <?= json_encode(array_sum($laporanSisa)) ?>
                        ],
                        backgroundColor: ['#8fb9c5', '#146c7c', '#f4b740']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });
        </script>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Penyewa</th>
                        <th>Kamar</th>
                        <th>Periode</th>
                        <th>Jatuh Tempo</th>
                        <th>Tagihan</th>
                        <th>Dibayar</th>
                        <th>Sisa</th>
                        <th>Transaksi</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $sisa = max(0, (float) $row['harga_sewa'] - (float) $row['total_bayar']);
                        $status = status_tagihan($sisa, $row['tanggal_jatuh_tempo'], $row['menunggu_verifikasi']);
                        ?>
                        <tr>
                            <td><?= e($row['nama_penyewa']) ?></td>
                            <td><?= e($row['nomor_kamar']) ?></td>
                            <td><?= e($row['bulan']) ?> <?= e($row['tahun']) ?></td>
                            <td><?= e($row['tanggal_jatuh_tempo']) ?></td>
                            <td><?= rupiah($row['harga_sewa']) ?></td>
                            <td><?= rupiah($row['total_bayar']) ?></td>
                            <td><?= rupiah($sisa) ?></td>
                            <td><?= e($row['jumlah_transaksi']) ?>x</td>
                            <td><?= e($status) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="9">Belum ada data laporan.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php
    }

    if ($page === 'kamar_kosong') {
        $kamarKosong = query_all("SELECT * FROM kamar WHERE status_kamar = 'Kosong' ORDER BY nomor_kamar");
?>
    <section class="panel">
        <h2>Kamar Kosong</h2>
        <?php render_column_guide([
            'Kamar' => 'Nomor kamar yang tersedia.',
            'Tipe' => 'Kategori kamar, misalnya standar atau VIP.',
            'Harga' => 'Biaya sewa kamar per bulan.',
            'Fasilitas' => 'Fasilitas yang tersedia di dalam kamar.',
            'Keterangan' => 'Catatan tambahan tentang kamar.'
        ]); ?>
        <?php render_table($kamarKosong, ['nomor_kamar' => 'Kamar', 'tipe_kamar' => 'Tipe', 'harga_sewa' => 'Harga', 'fasilitas' => 'Fasilitas', 'keterangan' => 'Keterangan'], true); ?>
    </section>
<?php
    }

    if ($page === 'pembayaran_saya') {
        $penyewa = current_penyewa();
        $rows = $penyewa ? query_all('SELECT * FROM pembayaran WHERE id_penyewa = ? ORDER BY id_pembayaran DESC', [$penyewa['id_penyewa']]) : [];
        $jatuhTempoSql = sql_jatuh_tempo_expr();
        $tagihanSaya = $penyewa ? query_all(
            "SELECT
            b.bulan,
            b.tahun,
            {$jatuhTempoSql} AS tanggal_jatuh_tempo,
            k.harga_sewa,
            SUM(CASE WHEN b.status_bayar IN ('Lunas', 'Belum Lunas') THEN b.jumlah_bayar ELSE 0 END) AS total_bayar,
            SUM(CASE WHEN b.status_bayar = 'Menunggu Verifikasi' THEN b.jumlah_bayar ELSE 0 END) AS menunggu_verifikasi
        FROM pembayaran b
        JOIN kamar k ON k.id_kamar = b.id_kamar
        WHERE b.id_penyewa = ?
        GROUP BY b.id_penyewa, b.id_kamar, k.harga_sewa, b.bulan, b.tahun
        ORDER BY b.tahun DESC, MAX(b.id_pembayaran) DESC",
            [$penyewa['id_penyewa']]
        ) : [];
        $bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $selectedBulan = $_GET['bulan'] ?? $bulan[((int) date('n')) - 1];
        $selectedTahun = $_GET['tahun'] ?? date('Y');
        $selectedJumlah = $_GET['jumlah'] ?? ($penyewa['harga_sewa'] ?? '');
        $selectedKeterangan = isset($_GET['bulan'], $_GET['tahun'])
            ? 'Pembayaran kekurangan ' . $_GET['bulan'] . ' ' . $_GET['tahun']
            : '';
?>
    <section class="page-note">
        <div class="page-note-icon"><i class="bi bi-upload"></i></div>
        <div>
            <strong>Upload Bukti Pembayaran Transfer</strong>
            <p>Gunakan fitur ini jika Anda sudah melakukan transfer sewa kos. Pilih bulan dan tahun pembayaran, isi nominal transfer, lalu unggah foto bukti pembayaran. Jatuh tempo tagihan adalah tanggal 10 setiap bulan, dan status akan menjadi Menunggu Verifikasi sampai admin mengecek bukti tersebut.</p>
        </div>
    </section>
    <section class="panel">
        <h2>Kirim Bukti Pembayaran</h2>
        <?php render_column_guide([
            'Bulan dan tahun' => 'Periode tagihan yang ingin dibayar.',
            'Jumlah transfer' => 'Nominal uang yang sudah ditransfer.',
            'Metode bayar' => 'Pembayaran penyewa menggunakan transfer bank.',
            'Bukti transfer' => 'Foto atau gambar bukti pembayaran yang akan dicek admin.',
            'Catatan transfer' => 'Keterangan tambahan, misalnya nama bank atau nomor referensi.'
        ]); ?>
        <form class="grid-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_pembayaran_penyewa">
            <div class="form-field">
                <label>Bulan tagihan</label>
                <select name="bulan"><?php foreach ($bulan as $b): ?><option <?= $selectedBulan === $b ? 'selected' : '' ?>><?= e($b) ?></option><?php endforeach; ?></select>
            </div>
            <div class="form-field">
                <label>Tahun tagihan</label>
                <input name="tahun" type="number" value="<?= e($selectedTahun) ?>" required>
            </div>
            <div class="form-field">
                <label>Jumlah transfer</label>
                <input name="jumlah_bayar" type="number" placeholder="Jumlah transfer" value="<?= e($selectedJumlah) ?>" required>
            </div>
            <div class="form-field">
                <label>Metode pembayaran</label>
                <select name="metode_bayar" disabled>
                    <option>Transfer bank</option>
                </select>
            </div>
            <div class="form-field">
                <label>Upload bukti transfer</label>
                <input name="bukti_transfer" type="file" accept="image/jpeg,image/png,image/webp" required>
            </div>
            <div class="form-field">
                <label>Catatan transfer</label>
                <input name="keterangan" placeholder="Contoh: BCA a.n. Ahmad" value="<?= e($selectedKeterangan) ?>">
            </div>
            <button type="submit"><i class="bi bi-upload me-1"></i>Kirim Bukti Transfer</button>
        </form>
    </section>
    <section class="panel">
        <h2>Ringkasan Tagihan Saya</h2>
        <?php render_column_guide([
            'Periode' => 'Bulan dan tahun tagihan sewa.',
            'Jatuh Tempo' => 'Batas akhir pembayaran sebelum terlambat.',
            'Tagihan' => 'Total sewa kamar yang harus dibayar.',
            'Dibayar' => 'Total pembayaran yang sudah diterima atau valid.',
            'Sisa' => 'Kekurangan pembayaran yang masih harus dibayar.',
            'Status' => 'Kondisi tagihan, seperti lunas, belum lunas, menunggu verifikasi, atau terlambat.',
            'Aksi' => 'Tombol untuk mengisi otomatis nominal kekurangan pembayaran.'
        ]); ?>
        <div class="table-wrap table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Periode</th>
                        <th>Jatuh Tempo</th>
                        <th>Tagihan</th>
                        <th>Dibayar</th>
                        <th>Sisa</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tagihanSaya as $row): ?>
                        <?php
                        $sisa = max(0, (float) $row['harga_sewa'] - (float) $row['total_bayar']);
                        $status = status_tagihan($sisa, $row['tanggal_jatuh_tempo'], $row['menunggu_verifikasi']);
                        ?>
                        <tr>
                            <td><?= e($row['bulan']) ?> <?= e($row['tahun']) ?></td>
                            <td><?= e($row['tanggal_jatuh_tempo']) ?></td>
                            <td><?= rupiah($row['harga_sewa']) ?></td>
                            <td><?= rupiah($row['total_bayar']) ?></td>
                            <td><?= rupiah($sisa) ?></td>
                            <td><?= e($status) ?></td>
                            <td>
                                <?php if ($sisa > 0): ?>
                                    <a class="btn btn-sm btn-primary" href="index.php?page=pembayaran_saya&bulan=<?= e($row['bulan']) ?>&tahun=<?= e($row['tahun']) ?>&jumlah=<?= e($sisa) ?>">
                                        <i class="bi bi-wallet2 me-1"></i>Bayar Kekurangan
                                    </a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$tagihanSaya): ?>
                        <tr>
                            <td colspan="7">Belum ada tagihan pembayaran.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <section class="panel">
        <h2>Riwayat Pembayaran Saya</h2>
        <?php render_column_guide([
            'Kode' => 'Nomor unik transaksi pembayaran.',
            'Bulan dan Tahun' => 'Periode sewa yang dibayar.',
            'Tanggal' => 'Tanggal pembayaran dikirim.',
            'Jatuh Tempo' => 'Batas akhir pembayaran periode tersebut.',
            'Jumlah' => 'Nominal pembayaran yang dikirim.',
            'Metode' => 'Cara pembayaran, khusus penyewa menggunakan transfer bank.',
            'Status' => 'Status validasi pembayaran dari admin.',
            'Bukti' => 'File bukti transfer yang pernah diunggah.'
        ]); ?>
        <?php render_table($rows, ['kode_pembayaran' => 'Kode', 'bulan' => 'Bulan', 'tahun' => 'Tahun', 'tanggal_bayar' => 'Tanggal', 'tanggal_jatuh_tempo' => 'Jatuh Tempo', 'jumlah_bayar' => 'Jumlah', 'metode_bayar' => 'Metode', 'status_bayar' => 'Status', 'bukti_transfer' => 'Bukti'], true); ?>
    </section>
<?php
    }

    if ($page === 'led_saya') {
        $penyewa = current_penyewa();
        $devicesSaya = $penyewa ? query_all("SELECT d.*, k.nomor_kamar FROM devices d JOIN kamar k ON k.id_kamar=d.id_kamar WHERE d.id_kamar = ? ORDER BY d.nama_device", [$penyewa['id_kamar']]) : [];
?>
    <section class="page-note">
        <div class="page-note-icon"><i class="bi bi-cpu"></i></div>
        <div>
            <strong>Device Kamar Saya</strong>
            <p>Device yang tampil di halaman ini adalah perangkat yang sudah ditambahkan admin pada kamar Anda. Jika perangkat belum muncul, pastikan admin memilih kamar yang sama dengan kamar penyewa.</p>
        </div>
    </section>
    <section class="panel">
        <h2>Kontrol Device Kamar Saya</h2>
        <?php render_column_guide([
            'Kamar' => 'Nomor kamar penyewa.',
            'Device' => 'Nama perangkat yang dapat dikontrol.',
            'Jenis' => 'Kategori perangkat, seperti lampu, kipas, LED, atau lainnya.',
            'Status' => 'Kondisi perangkat saat ini.',
            'Update' => 'Waktu terakhir device diperbarui.',
            'Aksi' => 'Tombol untuk menyalakan atau mematikan perangkat.'
        ]); ?>
        <?php if (!$penyewa): ?>
            <div class="alert alert-warning">Akun penyewa belum terhubung dengan data kamar aktif. Hubungi admin.</div>
        <?php endif; ?>
        <?php render_table($devicesSaya, ['nomor_kamar' => 'Kamar', 'nama_device' => 'Device', 'jenis_device' => 'Jenis', 'status_device' => 'Status', 'updated_at' => 'Update'], false, function ($row) {
            $target = $row['status_device'] === 'Menyala' ? 'Mati' : 'Menyala';
            $icon = $target === 'Menyala' ? 'bi-lightbulb-fill' : 'bi-power';
            return '<a class="btn btn-sm btn-primary" href="index.php?page=led_saya&action=toggle_device&id=' . e($row['id_device']) . '&status=' . e($target) . '"><i class="bi ' . e($icon) . ' me-1"></i>' . e($target) . '</a>';
        }); ?>
    </section>
<?php
    }

    function render_table($rows, $columns, $money = false, $actionRenderer = null)
    {
?>
    <div class="table-wrap table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <?php foreach ($columns as $label): ?>
                        <th><?= e($label) ?></th>
                    <?php endforeach; ?>
                    <?php if ($actionRenderer): ?><th>Aksi</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="<?= count($columns) + ($actionRenderer ? 1 : 0) ?>">Belum ada data.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <?php foreach ($columns as $key => $label): ?>
                            <td>
                                <?php
                                $value = $row[$key] ?? '';
                                echo $money && in_array($key, ['harga_sewa', 'jumlah_bayar'], true) ? rupiah($value) : e($value);
                                ?>
                            </td>
                        <?php endforeach; ?>
                        <?php if ($actionRenderer): ?><td><?= $actionRenderer($row) ?></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php
    }

    render_footer();
