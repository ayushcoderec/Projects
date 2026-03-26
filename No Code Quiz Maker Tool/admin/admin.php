<?php
/**
 * Single-Page Quiz Builder Admin Panel
 * Includes: Auth, API, Image Processing, Quiz Creation, History, and Admin Management
 */

// 1. CONFIGURATION
const DB_HOST = 'sql307.ezyro.com';
const DB_NAME = 'ezyro_40109632_quiz';
const DB_USER = 'ezyro_40109632';
const DB_PASS = '54fb7a4bc';
const BASE_URL = 'http://abcddfg.liveblog365.com/admin/'; // URL where this file lives
const IMG_PATH = 'images/'; // Local folder

session_start();

// 2. DATABASE CONNECTION
try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// 3. IMAGE PROCESSING ENGINE
function processAndUploadImage($file, $quizId, $subDir) {
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) return null;
    
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($file['type'], $allowed)) return null;

    $targetDir = IMG_PATH . "quiz_$quizId/$subDir/";
    if (!file_exists($targetDir)) mkdir($targetDir, 0755, true);

    $fileName = bin2hex(random_bytes(8)) . '.webp';
    $targetFile = $targetDir . $fileName;

    switch ($file['type']) {
        case 'image/jpeg': $img = imagecreatefromjpeg($file['tmp_name']); break;
        case 'image/png':  $img = imagecreatefrompng($file['tmp_name']); break;
        case 'image/webp': $img = imagecreatefromwebp($file['tmp_name']); break;
        default: return null;
    }

    if ($img) {
        imagewebp($img, $targetFile, 80);
        imagedestroy($img);
        return BASE_URL . $targetFile;
    }
    return null;
}

// 4. API REQUEST HANDLING
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    // Login Handler
    if ($action === 'login') {
        $user = $_POST['username'] ?? '';
        $pass = $_POST['password'] ?? '';
        $stmt = $pdo->prepare("SELECT id, password_hash FROM admins WHERE username = ?");
        $stmt->execute([$user]);
        $admin = $stmt->fetch();
        if ($admin && password_verify($pass, $admin['password_hash'])) {
            $_SESSION['admin_id'] = $admin['id'];
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
        }
        exit;
    }

    if (!isset($_SESSION['admin_id'])) {
        http_response_code(403);
        exit(json_encode(['error' => 'Unauthorized']));
    }

    // Admin Creation Handler
    if ($action === 'add_admin') {
        $new_user = trim($_POST['new_username'] ?? '');
        $new_pass = $_POST['new_password'] ?? '';
        if (strlen($new_user) < 3 || strlen($new_pass) < 6) {
            echo json_encode(['success' => false, 'error' => 'Username (min 3) or Password (min 6) too short.']);
            exit;
        }
        $check = $pdo->prepare("SELECT id FROM admins WHERE username = ?");
        $check->execute([$new_user]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Username already exists.']);
            exit;
        }
        $hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO admins (username, password_hash) VALUES (?, ?)");
        echo json_encode(['success' => $stmt->execute([$new_user, $hash])]);
        exit;
    }

    // Quiz Creation Handler
    if ($action === 'create_quiz') {
        try {
            $pdo->beginTransaction();
            $title = $_POST['title'] ?? 'Untitled Quiz';
            $desc = $_POST['description'] ?? '';
            $timer = (int)($_POST['timer'] ?? 0);

            $stmt = $pdo->prepare("INSERT INTO quizzes (title, description, timer_minutes) VALUES (?, ?, ?)");
            $stmt->execute([$title, $desc, $timer]);
            $quizId = $pdo->lastInsertId();

            $questions = json_decode($_POST['questions'], true);
            foreach ($questions as $qIdx => $q) {
                $qImg = isset($_FILES["q_img_$qIdx"]) ? processAndUploadImage($_FILES["q_img_$qIdx"], $quizId, 'questions') : null;
                $stmt = $pdo->prepare("INSERT INTO questions (quiz_id, question_text, question_image, type) VALUES (?, ?, ?, ?)");
                $stmt->execute([$quizId, $q['text'], $qImg, $q['type']]);
                $qId = $pdo->lastInsertId();

                foreach ($q['options'] as $oIdx => $opt) {
                    $oImg = isset($_FILES["opt_img_{$qIdx}_{$oIdx}"]) ? processAndUploadImage($_FILES["opt_img_{$qIdx}_{$oIdx}"], $quizId, 'options') : null;
                    $stmt = $pdo->prepare("INSERT INTO options (question_id, option_text, option_image, is_correct) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$qId, $opt['text'], $oImg, $opt['is_correct'] ? 1 : 0]);
                }
            }
            $pdo->commit();
            echo json_encode(['success' => true, 'quiz_id' => $quizId]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // Delete Quiz Handler
    if ($action === 'delete_quiz') {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM quizzes WHERE id = ?");
        echo json_encode(['success' => $stmt->execute([$id])]);
        exit;
    }
}

// 5. DATA FETCHING (GET)
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['admin_id'])) exit(json_encode(['error' => 'Unauthorized']));

    if ($_GET['action'] === 'export' && isset($_GET['id'])) {
        $quizId = (int)$_GET['id'];
        $stmt = $pdo->prepare("SELECT * FROM quizzes WHERE id = ?");
        $stmt->execute([$quizId]);
        $quiz = $stmt->fetch();
        $stmt = $pdo->prepare("SELECT * FROM questions WHERE quiz_id = ?");
        $stmt->execute([$quizId]);
        $questions = $stmt->fetchAll();
        foreach ($questions as &$q) {
            $stmt = $pdo->prepare("SELECT * FROM options WHERE question_id = ?");
            $stmt->execute([$q['id']]);
            $q['options'] = $stmt->fetchAll();
        }
        echo json_encode(['quiz' => $quiz, 'questions' => $questions]);
        exit;
    }

    if ($_GET['action'] === 'list_quizzes') {
        $stmt = $pdo->query("SELECT * FROM quizzes ORDER BY created_at DESC");
        echo json_encode($stmt->fetchAll());
        exit;
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QuizBuilder Pro Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .loading { pointer-events: none; opacity: 0.6; }
        .custom-scroll::-webkit-scrollbar { width: 5px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #6366f1; border-radius: 10px; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
    </style>
</head>
<body class="bg-gray-50 text-gray-900 font-sans">

    <?php if (!isset($_SESSION['admin_id'])): ?>
    <!-- LOGIN PAGE -->
    <div class="min-h-screen flex items-center justify-center p-6">
        <div class="bg-white p-10 rounded-3xl shadow-2xl w-full max-w-md border border-gray-100">
            <h1 class="text-4xl font-black text-indigo-600 mb-2 text-center">Login</h1>
            <p class="text-gray-400 mb-8 font-medium text-center">Administrator Panel</p>
            <form onsubmit="handleLogin(event)" class="space-y-5">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-widest text-gray-400 mb-2">Username</label>
                    <input type="text" name="username" required class="w-full px-5 py-3 rounded-2xl bg-gray-50 border-none ring-1 ring-gray-200 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-widest text-gray-400 mb-2">Password</label>
                    <input type="password" name="password" required class="w-full px-5 py-3 rounded-2xl bg-gray-50 border-none ring-1 ring-gray-200 focus:ring-2 focus:ring-indigo-500 outline-none transition">
                </div>
                <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-2xl font-bold shadow-lg shadow-indigo-100 hover:bg-indigo-700 transition transform active:scale-95">Sign In</button>
            </form>
        </div>
    </div>
    <?php else: ?>
    <!-- DASHBOARD PAGE -->
    <nav class="bg-white border-b border-gray-100 px-8 py-4 flex justify-between items-center sticky top-0 z-50">
        <div class="flex items-center gap-6">
            <div class="flex items-center gap-2">
                <div class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center text-white font-black text-xl">Q</div>
                <span class="text-xl font-black tracking-tight">QuizBuilder <span class="text-indigo-600">Admin</span></span>
            </div>
            <div class="hidden md:flex gap-4 ml-6 border-l pl-6 border-gray-100">
                <button onclick="switchTab('builder')" class="tab-btn text-indigo-600 font-bold text-sm" data-tab="builder">Builder</button>
                <button onclick="switchTab('history')" class="tab-btn text-gray-400 font-bold text-sm hover:text-indigo-600 transition" data-tab="history">History</button>
                <button onclick="switchTab('admins')" class="tab-btn text-gray-400 font-bold text-sm hover:text-indigo-600 transition" data-tab="admins">Settings</button>
            </div>
        </div>
        <a href="?logout=1" class="text-gray-400 hover:text-red-500 font-bold text-sm transition" onclick="return confirm('Logout?')">Logout</a>
    </nav>

    <div class="max-w-4xl mx-auto py-12 px-6">
        
        <!-- QUIZ BUILDER -->
        <div id="builder-tab" class="tab-content active">
            <div class="flex justify-between items-end mb-10">
                <div>
                    <h2 class="text-4xl font-black text-gray-800">New Quiz</h2>
                    <p class="text-gray-400 mt-1">Design, upload, and export instantly.</p>
                </div>
                <button onclick="saveAndExport()" id="publish-btn" class="bg-indigo-600 text-white px-10 py-4 rounded-2xl font-black shadow-xl shadow-indigo-100 hover:bg-indigo-700 transition">
                    Publish Quiz
                </button>
            </div>

            <div class="bg-white rounded-3xl p-8 border border-gray-100 shadow-sm mb-8 space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                    <div class="md:col-span-3">
                        <label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">Title</label>
                        <input type="text" id="q-title" class="w-full text-2xl font-bold border-b-2 border-gray-100 focus:border-indigo-600 outline-none pb-2 transition" placeholder="Enter Quiz Title...">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">Timer (Mins)</label>
                        <input type="number" id="q-timer" value="0" class="w-full bg-gray-50 rounded-xl p-3 border-none ring-1 ring-gray-100 outline-none">
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">Description</label>
                    <textarea id="q-desc" class="w-full bg-gray-50 rounded-xl p-4 border-none ring-1 ring-gray-100 outline-none focus:ring-2 focus:ring-indigo-500 min-h-[80px]" placeholder="Brief introduction..."></textarea>
                </div>
            </div>
            <div id="questions-list" class="space-y-6"></div>
            <button onclick="addQuestion()" class="w-full mt-10 py-8 border-4 border-dashed border-gray-200 rounded-3xl text-gray-300 font-black text-xl hover:border-indigo-200 hover:text-indigo-400 hover:bg-indigo-50 transition-all flex items-center justify-center gap-3">
                <span class="text-3xl">+</span> Add New Question
            </button>
        </div>

        <!-- HISTORY TAB -->
        <div id="history-tab" class="tab-content">
            <h2 class="text-4xl font-black text-gray-800 mb-2">Quiz History</h2>
            <p class="text-gray-400 mb-8 font-medium">Manage and re-export your previously created quizzes.</p>
            <div id="history-list" class="space-y-4">
                <div class="text-center py-20 text-gray-300">Loading history...</div>
            </div>
        </div>

        <!-- SETTINGS TAB -->
        <div id="admins-tab" class="tab-content">
            <h2 class="text-4xl font-black text-gray-800 mb-2">Settings</h2>
            <p class="text-gray-400 mb-10 font-medium">Manage administrators and system preferences.</p>
            <div class="bg-white rounded-3xl p-10 border border-gray-100 shadow-sm max-w-lg">
                <h3 class="text-xl font-bold mb-6 text-indigo-600">Add New Admin</h3>
                <form onsubmit="handleNewAdmin(event)" class="space-y-6">
                    <input type="text" name="new_username" required placeholder="Username" class="w-full px-5 py-3 rounded-2xl bg-gray-50 border-none ring-1 ring-gray-200 outline-none">
                    <input type="password" name="new_password" required placeholder="Password" class="w-full px-5 py-3 rounded-2xl bg-gray-50 border-none ring-1 ring-gray-200 outline-none">
                    <button type="submit" class="w-full bg-gray-900 text-white py-4 rounded-2xl font-bold hover:bg-black transition">Create Account</button>
                </form>
            </div>
        </div>

    </div>

    <!-- Export Modal -->
    <div id="modal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm z-[100] hidden flex items-center justify-center p-6">
        <div class="bg-white rounded-3xl p-10 max-w-3xl w-full shadow-2xl">
            <h3 class="text-3xl font-black mb-4">Code Ready! 🚀</h3>
            <p class="text-gray-500 mb-6">Copy and save as an <code>.html</code> file. It contains all data internally.</p>
            <textarea id="output" readonly class="w-full h-80 bg-gray-900 text-indigo-400 font-mono text-xs p-6 rounded-2xl border-none outline-none custom-scroll mb-6"></textarea>
            <div class="flex justify-end gap-4">
                <button onclick="document.getElementById('modal').classList.add('hidden')" class="px-8 py-3 text-gray-400 font-bold">Close</button>
                <button onclick="copyToClipboard()" class="bg-indigo-600 text-white px-10 py-3 rounded-xl font-bold hover:bg-indigo-700">Copy Code</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    function switchTab(tab) {
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        document.getElementById(tab + '-tab').classList.add('active');
        document.querySelectorAll('.tab-btn').forEach(b => {
            b.classList.replace('text-indigo-600', 'text-gray-400');
            if(b.dataset.tab === tab) b.classList.replace('text-gray-400', 'text-indigo-600');
        });
        if(tab === 'history') loadHistory();
    }

    async function handleLogin(e) {
        e.preventDefault();
        const fd = new FormData(e.target);
        const res = await fetch('?action=login', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) location.reload(); else alert(data.error);
    }

    async function handleNewAdmin(e) {
        e.preventDefault();
        const fd = new FormData(e.target);
        const res = await fetch('?action=add_admin', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) { alert("Admin added!"); e.target.reset(); } else alert(data.error);
    }

    async function loadHistory() {
        const res = await fetch('?action=list_quizzes');
        const list = await res.json();
        const container = document.getElementById('history-list');
        if(list.length === 0) {
            container.innerHTML = '<div class="text-center py-20 text-gray-400">No quizzes created yet.</div>';
            return;
        }
        container.innerHTML = list.map(q => `
            <div class="bg-white p-6 rounded-3xl border border-gray-100 flex items-center justify-between shadow-sm">
                <div>
                    <h4 class="font-bold text-lg">${q.title}</h4>
                    <p class="text-xs text-gray-400">Created: ${new Date(q.created_at).toLocaleDateString()}</p>
                </div>
                <div class="flex gap-2">
                    <button onclick="generateExport(${q.id})" class="text-indigo-600 bg-indigo-50 px-4 py-2 rounded-xl text-xs font-bold hover:bg-indigo-100">Export Code</button>
                    <button onclick="deleteQuiz(${q.id})" class="text-red-500 bg-red-50 px-4 py-2 rounded-xl text-xs font-bold hover:bg-red-100">Delete</button>
                </div>
            </div>
        `).join('');
    }

    async function deleteQuiz(id) {
        if(!confirm('Delete this quiz permanently?')) return;
        const fd = new FormData(); fd.append('id', id);
        const res = await fetch('?action=delete_quiz', { method: 'POST', body: fd });
        if((await res.json()).success) loadHistory();
    }

    let questionCount = 0;
    function addQuestion() {
        const id = questionCount++;
        const html = `
            <div class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden q-card" data-idx="${id}">
                <div class="bg-gray-50/50 px-8 py-4 border-b border-gray-100 flex justify-between items-center">
                    <span class="text-[10px] font-black uppercase tracking-widest text-indigo-500">Question #${id + 1}</span>
                    <button onclick="this.closest('.q-card').remove()" class="text-gray-300 hover:text-red-500">Remove</button>
                </div>
                <div class="p-8 space-y-6">
                    <textarea class="q-text w-full text-xl font-bold bg-transparent border-none outline-none placeholder-gray-200 resize-none" placeholder="Type question here..." rows="2"></textarea>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <select class="q-type w-full bg-gray-50 p-3 rounded-xl ring-1 ring-gray-100 text-sm font-bold border-none outline-none">
                            <option value="single">Single Correct (MCQ)</option>
                            <option value="multiple">Multiple Correct</option>
                            <option value="blank">Fill in the Blanks</option>
                        </select>
                        <input type="file" class="q-img text-xs text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-bold file:bg-indigo-50 file:text-indigo-600">
                    </div>
                    <div class="space-y-3">
                        <div class="options-container space-y-2"></div>
                        <button onclick="addOption(${id})" class="text-indigo-600 text-xs font-black uppercase tracking-widest">+ Add Option</button>
                    </div>
                </div>
            </div>`;
        document.getElementById('questions-list').insertAdjacentHTML('beforeend', html);
        addOption(id); addOption(id);
    }

    function addOption(qIdx) {
        const container = document.querySelector(`[data-idx="${qIdx}"] .options-container`);
        const oIdx = container.children.length;
        const html = `
            <div class="flex items-center gap-3 group o-row" data-oidx="${oIdx}">
                <input type="checkbox" class="o-correct w-6 h-6 rounded-lg ring-2 ring-gray-100 text-indigo-600 cursor-pointer border-none">
                <input type="text" class="o-text flex-1 bg-gray-50 px-4 py-2 rounded-xl ring-1 ring-gray-100 text-sm outline-none border-none" placeholder="Option text...">
                <input type="file" class="o-img w-24 text-[8px] opacity-0 group-hover:opacity-100 transition cursor-pointer">
                <button onclick="this.parentElement.remove()" class="text-gray-200 hover:text-red-400">×</button>
            </div>`;
        container.insertAdjacentHTML('beforeend', html);
    }

    async function saveAndExport() {
        const btn = document.getElementById('publish-btn');
        btn.classList.add('loading'); btn.innerText = "Processing...";
        const formData = new FormData();
        formData.append('title', document.getElementById('q-title').value || 'Untitled Quiz');
        formData.append('description', document.getElementById('q-desc').value);
        formData.append('timer', document.getElementById('q-timer').value);
        const questionsData = [];
        document.querySelectorAll('.q-card').forEach((card, qIdx) => {
            const q = { text: card.querySelector('.q-text').value, type: card.querySelector('.q-type').value, options: [] };
            const qImg = card.querySelector('.q-img').files[0];
            if (qImg) formData.append(`q_img_${qIdx}`, qImg);
            card.querySelectorAll('.o-row').forEach((opt, oIdx) => {
                q.options.push({ text: opt.querySelector('.o-text').value, is_correct: opt.querySelector('.o-correct').checked });
                const oImg = opt.querySelector('.o-img').files[0];
                if (oImg) formData.append(`opt_img_${qIdx}_${oIdx}`, oImg);
            });
            questionsData.push(q);
        });
        formData.append('questions', JSON.stringify(questionsData));
        try {
            const res = await fetch('?action=create_quiz', { method: 'POST', body: formData });
            const result = await res.json();
            if (result.success) generateExport(result.quiz_id); else alert(result.error);
        } catch (e) { alert("Upload error."); } finally { btn.classList.remove('loading'); btn.innerText = "Publish Quiz"; }
    }

    async function generateExport(id) {
        const res = await fetch(`?action=export&id=${id}`);
        const data = await res.json();
        
        // FIXED EXPORT ENGINE: Bakes actual values into the code
        const html = `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>${data.quiz.title}</title>
    <script src="https://cdn.tailwindcss.com"><\/script>
    <style>
        .opt-item:hover { border-color: #6366f1; background: #f5f3ff; }
        .active { border-color: #6366f1; background: #eef2ff; box-shadow: 0 10px 15px -3px rgba(99, 102, 241, 0.1); }
    </style>
</head>
<body class="bg-gray-50 min-h-screen p-6 md:p-12 font-sans">
    <div class="max-w-2xl mx-auto" id="app">
        <div class="bg-white rounded-[40px] shadow-2xl p-10 md:p-14 border border-gray-100">
            <div id="quiz-intro">
                <h1 class="text-4xl font-black text-gray-900 mb-4">${data.quiz.title}</h1>
                <p class="text-gray-400 font-medium mb-10">${data.quiz.description || ''}</p>
                <button onclick="startQuiz()" class="bg-indigo-600 text-white px-12 py-5 rounded-3xl font-black text-lg shadow-xl shadow-indigo-100">Start Quiz</button>
            </div>
            <div id="quiz-main" class="hidden">
                <div class="flex justify-between items-center mb-8">
                    <span class="text-[10px] font-black uppercase tracking-widest text-indigo-500">Question <span id="current-num">1</span>/${data.questions.length}</span>
                </div>
                <div id="q-content" class="space-y-6">
                    <img id="q-image" class="w-full rounded-3xl hidden" src="">
                    <h2 id="q-text" class="text-2xl font-black text-gray-800 leading-tight"></h2>
                    <div id="options-box" class="space-y-3 pt-4"></div>
                    <button onclick="submitAnswer()" class="w-full mt-10 bg-gray-900 text-white py-5 rounded-3xl font-black">Submit Answer</button>
                </div>
            </div>
            <div id="quiz-result" class="hidden text-center">
                <div class="text-7xl mb-6">🏆</div>
                <h2 class="text-4xl font-black mb-2">Quiz Finished!</h2>
                <div class="bg-indigo-50 py-10 rounded-[40px] mt-8">
                    <span class="block text-gray-400 text-xs font-black mb-2 uppercase tracking-widest">Final Score</span>
                    <span class="text-6xl font-black text-indigo-600"><span id="score-val">0</span> / ${data.questions.length}</span>
                </div>
            </div>
        </div>
    </div>
    <script>
        const data = ${JSON.stringify(data)};
        let currentIdx = 0, score = 0, selected = [];

        function startQuiz() {
            document.getElementById('quiz-intro').classList.add('hidden');
            document.getElementById('quiz-main').classList.remove('hidden');
            renderQuestion();
        }

        function renderQuestion() {
            const q = data.questions[currentIdx];
            selected = [];
            document.getElementById('current-num').innerText = currentIdx + 1;
            document.getElementById('q-text').innerText = q.question_text;
            const img = document.getElementById('q-image');
            if(q.question_image) { img.src = q.question_image; img.classList.remove('hidden'); } else img.classList.add('hidden');
            const box = document.getElementById('options-box'); box.innerHTML = '';
            if(q.type === 'blank') {
                box.innerHTML = '<input type="text" id="blank-input" class="w-full p-5 rounded-3xl bg-gray-50 ring-2 ring-gray-100 outline-none text-lg font-bold" placeholder="Type answer...">';
            } else {
                q.options.forEach((opt, i) => {
                    const div = document.createElement('div');
                    div.className = "opt-item p-5 border-2 border-gray-50 rounded-3xl cursor-pointer transition flex items-center gap-4";
                    div.onclick = () => {
                        if(q.type === 'single') { document.querySelectorAll('.opt-item').forEach(e => e.classList.remove('active')); selected = [i]; }
                        else { if(selected.includes(i)) selected = selected.filter(x => x !== i); else selected.push(i); }
                        div.classList.toggle('active');
                    };
                    div.innerHTML = \`
                        <div class="w-8 h-8 rounded-xl bg-gray-50 flex items-center justify-center font-black text-xs text-gray-400">\${String.fromCharCode(65+i)}</div>
                        <div class="flex-1">
                            \${opt.option_image ? '<img src="'+opt.option_image+'" class="h-24 rounded-xl mb-3 shadow-sm">' : ''}
                            <div class="font-bold text-gray-700">\${opt.option_text}</div>
                        </div>\`;
                    box.appendChild(div);
                });
            }
        }

        function submitAnswer() {
            const q = data.questions[currentIdx];
            let correct = false;
            if(q.type === 'blank') {
                const val = document.getElementById('blank-input').value.trim().toLowerCase();
                correct = q.options.some(o => o.option_text.toLowerCase() === val);
            } else {
                const ans = q.options.map((o,i) => o.is_correct == 1 ? i : null).filter(i => i !== null);
                correct = JSON.stringify(selected.sort()) === JSON.stringify(ans.sort());
            }
            if(correct) score++;
            currentIdx++;
            if(currentIdx < data.questions.length) renderQuestion(); else finish();
        }

        function finish() {
            document.getElementById('quiz-main').classList.add('hidden');
            document.getElementById('quiz-result').classList.remove('hidden');
            document.getElementById('score-val').innerText = score;
        }
    <\/script>
</body>
</html>`;
        document.getElementById('output').value = html;
        document.getElementById('modal').classList.remove('hidden');
    }

    function copyToClipboard() {
        const text = document.getElementById("output");
        text.select(); document.execCommand("copy"); alert("Code copied!");
    }

    <?php if (isset($_SESSION['admin_id'])) echo "addQuestion();"; ?>
    </script>
</body>
</html>