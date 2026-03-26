<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ayush Tiwari</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .opt-item:hover { border-color: #6366f1; background: #f5f3ff; }
        .active { border-color: #6366f1; background: #eef2ff; box-shadow: 0 10px 15px -3px rgba(99, 102, 241, 0.1); }
    </style>
</head>
<body class="bg-gray-50 min-h-screen p-6 md:p-12 font-sans">
    <div class="max-w-2xl mx-auto" id="app">
        <div class="bg-white rounded-[40px] shadow-2xl p-10 md:p-14 border border-gray-100">
            <div id="quiz-intro">
                <h1 class="text-4xl font-black text-gray-900 mb-4">Ayush Tiwari</h1>
                <p class="text-gray-400 font-medium mb-10">great</p>
                <button onclick="startQuiz()" class="bg-indigo-600 text-white px-12 py-5 rounded-3xl font-black text-lg shadow-xl shadow-indigo-100">Start Quiz</button>
            </div>
            <div id="quiz-main" class="hidden">
                <div class="flex justify-between items-center mb-8">
                    <span class="text-[10px] font-black uppercase tracking-widest text-indigo-500">Question <span id="current-num">1</span>/2</span>
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
                    <span class="text-6xl font-black text-indigo-600"><span id="score-val">0</span> / 2</span>
                </div>
            </div>
        </div>
    </div>
    <script>
        const data = {"quiz":{"id":1,"title":"Ayush Tiwari","description":"great","timer_minutes":5,"created_at":"2026-01-07 09:29:49"},"questions":[{"id":1,"quiz_id":1,"question_text":"What does the Image says","question_image":"http://abcddfg.liveblog365.com/admin/images/quiz_1/questions/4776674f2dc4cd58.webp","type":"single","options":[{"id":1,"question_id":1,"option_text":"Education","option_image":null,"is_correct":1},{"id":2,"question_id":1,"option_text":"Educationn","option_image":null,"is_correct":0},{"id":3,"question_id":1,"option_text":"","option_image":"http://abcddfg.liveblog365.com/admin/images/quiz_1/options/b71d6dedc9759c31.webp","is_correct":0}]},{"id":2,"quiz_id":1,"question_text":"Your Name","question_image":null,"type":"blank","options":[{"id":4,"question_id":2,"option_text":"","option_image":null,"is_correct":0}]}]};
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
                    div.innerHTML = `
                        <div class="w-8 h-8 rounded-xl bg-gray-50 flex items-center justify-center font-black text-xs text-gray-400">${String.fromCharCode(65+i)}</div>
                        <div class="flex-1">
                            ${opt.option_image ? '<img src="'+opt.option_image+'" class="h-24 rounded-xl mb-3 shadow-sm">' : ''}
                            <div class="font-bold text-gray-700">${opt.option_text}</div>
                        </div>`;
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
    </script>
</body>
</html>