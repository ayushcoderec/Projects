<?php
// ==========================================
// 1. PHP BACKEND LOGIC (MySQL Integration)
// ==========================================
include '../config/db.php';

$db_error = null;



// Handle API Requests from React
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if (!$pdo) {
        echo json_encode(['error' => 'No database connection']);
        exit;
    }
    
    // FETCH ALL INVENTORY
    if ($_GET['action'] == 'fetch') {
        try {
            $stmt = $pdo->query("SELECT * FROM inventory ORDER BY updated_at DESC");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) { echo json_encode(['error' => $e->getMessage()]); }
        exit;
    }

    // ADD NEW BATCH
    if ($_GET['action'] == 'add' && $_SERVER['REQUEST_METHOD'] == 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        try {
            $sql = "INSERT INTO inventory (name, generic_name, category, batch_number, quantity, unit, cost_price, selling_price, expiry_date) 
                    VALUES (:name, :generic_name, :category, :batch_number, :quantity, :unit, :cost_price, :selling_price, :expiry_date)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':name' => $data['name'],
                ':generic_name' => $data['genericName'] ?? '',
                ':category' => $data['category'] ?? 'Other',
                ':batch_number' => $data['batchNumber'],
                ':quantity' => (int)$data['quantity'],
                ':unit' => $data['unit'] ?? 'Pieces',
                ':cost_price' => (float)($data['costPrice'] ?? 0),
                ':selling_price' => (float)$data['sellingPrice'],
                ':expiry_date' => $data['expiryDate']
            ]);
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) { echo json_encode(['error' => $e->getMessage()]); }
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MedStock Pro - Inventory</title>
    
    <!-- Load Libraries -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; margin: 0; }
        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .glass { background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(10px); }
        
        /* Fallback loader if JS fails */
        #root:empty::before {
            content: 'Loading System...';
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            color: #64748b;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div id="root"></div>

    <script type="text/babel">
        // Safe destructuring
        const { useState, useEffect, useMemo, useRef } = React;

        // --- CONFIG ---
        const GEMINI_API_KEY = ""; 

        // Helper: Lucide Icon
        const Icon = ({ name, className = "w-5 h-5" }) => {
            const iconRef = useRef(null);
            useEffect(() => {
                if (iconRef.current && window.lucide) {
                    iconRef.current.innerHTML = '';
                    const node = lucide[name];
                    if (node) lucide.createIcons({ icons: { [name]: node }, attrs: { class: className } });
                }
            }, [name, className]);
            return <span ref={iconRef} className="inline-flex"></span>;
        };

        function App() {
            const [inventory, setInventory] = useState([]);
            const [loading, setLoading] = useState(true);
            const [isAdding, setIsAdding] = useState(false);
            const [isScanning, setIsScanning] = useState(false);
            const [scanLoading, setScanLoading] = useState(false);
            const [searchTerm, setSearchTerm] = useState('');
            
            // Safe PHP to JS data passing
            const [error, setError] = useState(<?php echo json_encode($db_error); ?>);
            
            const [medSearchQuery, setMedSearchQuery] = useState('');
            const [selectedMedicine, setSelectedMedicine] = useState(null);

            const [formData, setFormData] = useState({
                name: '', genericName: '', category: 'Tablet', batchNumber: '',
                quantity: '', unit: 'Pieces', costPrice: '', sellingPrice: '',
                expiryDate: '', location: '',
            });

            const fetchInventory = async () => {
                try {
                    const response = await fetch('add_inventory.php?action=fetch');
                    const data = await response.json();
                    if (data.error) throw new Error(data.error);
                    setInventory(data);
                    setLoading(false);
                } catch (err) {
                    // Only set error if we don't already have a DB error
                    if (!error) setError("Failed to connect to backend API.");
                }
            };

            useEffect(() => {
                if (!error) {
                    fetchInventory();
                    const interval = setInterval(fetchInventory, 10000); // Check every 10s
                    return () => clearInterval(interval);
                }
            }, [error]);

            const uniqueMedicines = useMemo(() => {
                const meds = {};
                inventory.forEach(item => {
                    const key = item.name?.toLowerCase().trim();
                    if (key && !meds[key]) {
                        meds[key] = {
                            name: item.name, genericName: item.generic_name, category: item.category,
                            unit: item.unit, lastSelling: item.selling_price
                        };
                    }
                });
                return Object.values(meds);
            }, [inventory]);

            const medicineSuggestions = useMemo(() => {
                if (!medSearchQuery || selectedMedicine) return [];
                return uniqueMedicines.filter(m => 
                    m.name.toLowerCase().includes(medSearchQuery.toLowerCase())
                ).slice(0, 5);
            }, [medSearchQuery, uniqueMedicines, selectedMedicine]);

            const handleAddInventory = async (e) => {
                e.preventDefault();
                try {
                    const response = await fetch('add_inventory.php?action=add', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(formData)
                    });
                    const result = await response.json();
                    if (result.error) throw new Error(result.error);
                    
                    setIsAdding(false);
                    fetchInventory();
                    setFormData({ name: '', genericName: '', category: 'Tablet', batchNumber: '', quantity: '', unit: 'Pieces', costPrice: '', sellingPrice: '', expiryDate: '', location: '' });
                    setMedSearchQuery('');
                    setSelectedMedicine(null);
                } catch (err) { 
                    alert("Save failed: " + err.message);
                }
            };

            if (error) return (
                <div className="h-screen flex items-center justify-center p-6 bg-slate-50">
                    <div className="bg-white p-10 rounded-[2.5rem] shadow-2xl border border-red-50 text-center max-w-lg">
                        <div className="bg-red-50 w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-6">
                            <Icon name="AlertTriangle" className="w-10 h-10 text-red-500" />
                        </div>
                        <h2 className="text-2xl font-black text-slate-900">System Connection Error</h2>
                        <p className="text-slate-500 mt-4 text-sm leading-relaxed">{error}</p>
                        <div className="mt-8 p-4 bg-slate-50 rounded-2xl text-left text-xs font-mono text-slate-600">
                            <strong>Setup steps:</strong><br/>
                            1. Open phpMyAdmin<br/>
                            2. Create database: <b>medical_db</b><br/>
                            3. Create table: <b>inventory</b> (id, name, generic_name, category, batch_number, quantity, unit, cost_price, selling_price, expiry_date, updated_at)
                        </div>
                        <button onClick={() => location.reload()} className="mt-8 w-full py-4 bg-slate-900 text-white rounded-2xl font-bold hover:bg-black transition-all">Check Connection Again</button>
                    </div>
                </div>
            );

            return (
                <div className="min-h-screen">
                    <header className="glass border-b sticky top-0 z-40 px-8 py-5 flex justify-between items-center">
                        <div className="flex items-center gap-3">
                            <div className="bg-blue-600 p-2.5 rounded-2xl text-white shadow-xl shadow-blue-100">
                                <Icon name="Activity" />
                            </div>
                            <h1 className="text-2xl font-black tracking-tight text-slate-800">MEDSTOCK<span className="text-blue-600">PRO</span></h1>
                        </div>
                        <div className="flex gap-4">
                            <button onClick={() => setIsScanning(true)} className="bg-white px-5 py-3 rounded-2xl font-bold flex items-center gap-2 border border-slate-200 hover:shadow-lg transition-all text-slate-600">
                                <Icon name="Scan" className="w-4 h-4 text-indigo-500" /> AI Scan
                            </button>
                            <button onClick={() => { setIsAdding(true); }} className="bg-blue-600 text-white px-7 py-3 rounded-2xl font-bold flex items-center gap-2 shadow-xl shadow-blue-200 hover:bg-blue-700 hover:-translate-y-0.5 transition-all">
                                <Icon name="Plus" className="w-4 h-4" /> Add New Stock
                            </button>
                        </div>
                    </header>

                    <main className="p-8 max-w-6xl mx-auto">
                        <div className="relative mb-12">
                            <Icon name="Search" className="absolute left-6 top-1/2 -translate-y-1/2 text-slate-400" />
                            <input 
                                className="w-full pl-16 pr-8 py-6 bg-white border border-slate-100 rounded-[2rem] shadow-xl shadow-slate-100/50 outline-none text-lg font-medium focus:ring-4 focus:ring-blue-50/50 transition-all"
                                placeholder="Search inventory..."
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                            />
                        </div>

                        <div className="grid gap-5">
                            {inventory.filter(i => i.name?.toLowerCase().includes(searchTerm.toLowerCase())).map(item => (
                                <div key={item.id} className="bg-white p-7 rounded-[2rem] border border-slate-50 shadow-sm hover:shadow-xl transition-all flex flex-col md:flex-row md:items-center justify-between gap-6 group">
                                    <div className="flex items-center gap-6">
                                        <div className="bg-slate-50 p-4 rounded-2xl group-hover:bg-blue-50 transition-colors">
                                            <Icon name="Pill" className="text-slate-300 group-hover:text-blue-500 w-8 h-8" />
                                        </div>
                                        <div>
                                            <h3 className="text-xl font-black text-slate-900 uppercase tracking-tight">{item.name}</h3>
                                            <p className="text-sm font-bold text-slate-400">{item.generic_name || 'Formula not set'}</p>
                                            <div className="flex gap-2 mt-4">
                                                <span className="px-3 py-1 bg-indigo-50 text-indigo-600 rounded-lg text-[10px] font-black uppercase tracking-widest border border-indigo-100">BATCH #{item.batch_number}</span>
                                                <span className="px-3 py-1 bg-slate-100 text-slate-500 rounded-lg text-[10px] font-black uppercase tracking-widest">{item.category}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-12 px-8 py-4 bg-slate-50/50 rounded-3xl md:bg-transparent md:p-0">
                                        <div className="text-center">
                                            <p className="text-[10px] font-black text-slate-300 uppercase tracking-tighter mb-1">Available</p>
                                            <p className="text-2xl font-black text-slate-900">{item.quantity} <span className="text-xs font-bold text-slate-400">{item.unit}</span></p>
                                        </div>
                                        <div className="text-center">
                                            <p className="text-[10px] font-black text-slate-300 uppercase tracking-tighter mb-1">Unit Price</p>
                                            <p className="text-2xl font-black text-emerald-600">₹{item.selling_price}</p>
                                        </div>
                                        <div className="text-center">
                                            <p className="text-[10px] font-black text-slate-300 uppercase tracking-tighter mb-1">Expiry</p>
                                            <p className="text-sm font-black text-slate-900 bg-white px-3 py-1 rounded-lg border">{item.expiry_date}</p>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </main>

                    {/* MODAL: ADD BATCH */}
                    {isAdding && (
                        <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-md z-50 flex items-center justify-center p-4">
                            <div className="bg-white rounded-[2.5rem] w-full max-w-2xl shadow-2xl flex flex-col max-h-[90vh] overflow-hidden">
                                <div className="p-8 border-b flex justify-between items-center">
                                    <h2 className="text-2xl font-black text-slate-800">Stock Entry</h2>
                                    <button onClick={() => setIsAdding(false)} className="p-3 hover:bg-slate-100 rounded-full"><Icon name="X" /></button>
                                </div>
                                <div className="p-10 overflow-y-auto custom-scroll space-y-10">
                                    <div className="relative">
                                        <label className="text-[11px] font-black text-slate-400 uppercase tracking-widest ml-1">Search Medicine</label>
                                        <input 
                                            className="w-full mt-3 p-5 bg-slate-50 border-2 border-slate-100 rounded-3xl outline-none font-bold"
                                            placeholder="Start typing..."
                                            value={medSearchQuery || formData.name}
                                            onChange={(e) => {
                                                setMedSearchQuery(e.target.value);
                                                setFormData({...formData, name: e.target.value});
                                                if(selectedMedicine) setSelectedMedicine(null);
                                            }}
                                        />
                                        {medicineSuggestions.length > 0 && (
                                            <div className="absolute w-full bg-white border shadow-2xl rounded-3xl mt-3 z-50 overflow-hidden divide-y divide-slate-50">
                                                {medicineSuggestions.map((m, idx) => (
                                                    <div key={idx} onClick={() => {
                                                        setFormData({...formData, name: m.name, genericName: m.genericName, category: m.category, unit: m.unit, sellingPrice: m.lastSelling});
                                                        setSelectedMedicine(m);
                                                        setMedSearchQuery(m.name);
                                                    }} className="p-6 hover:bg-blue-50 cursor-pointer flex justify-between items-center">
                                                        <div>
                                                            <div className="font-black text-slate-900">{m.name}</div>
                                                            <div className="text-xs text-slate-500 font-bold">{m.genericName}</div>
                                                        </div>
                                                        <Icon name="ChevronRight" />
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                    <form onSubmit={handleAddInventory} className="grid grid-cols-2 gap-8">
                                        <div className={selectedMedicine ? "opacity-30" : ""}>
                                            <label className="text-[11px] font-black text-slate-400 uppercase">Formula</label>
                                            <input className="w-full mt-2 p-4 bg-slate-50 border-2 border-slate-100 rounded-2xl" value={formData.genericName} onChange={e => setFormData({...formData, genericName: e.target.value})} />
                                        </div>
                                        <div>
                                            <label className="text-[11px] font-black text-slate-400 uppercase">Batch #</label>
                                            <input required className="w-full mt-2 p-4 bg-slate-50 border-2 border-slate-100 rounded-2xl" value={formData.batchNumber} onChange={e => setFormData({...formData, batchNumber: e.target.value})} />
                                        </div>
                                        <div>
                                            <label className="text-[11px] font-black text-slate-400 uppercase">Quantity</label>
                                            <input type="number" required className="w-full mt-2 p-4 bg-slate-50 border-2 border-slate-100 rounded-2xl" value={formData.quantity} onChange={e => setFormData({...formData, quantity: e.target.value})} />
                                        </div>
                                        <div>
                                            <label className="text-[11px] font-black text-slate-400 uppercase">Price (₹)</label>
                                            <input type="number" step="0.01" required className="w-full mt-2 p-4 bg-slate-50 border-2 border-slate-100 rounded-2xl" value={formData.sellingPrice} onChange={e => setFormData({...formData, sellingPrice: e.target.value})} />
                                        </div>
                                        <div>
                                            <label className="text-[11px] font-black text-slate-400 uppercase">Expiry</label>
                                            <input type="date" required className="w-full mt-2 p-4 bg-slate-50 border-2 border-slate-100 rounded-2xl" value={formData.expiryDate} onChange={e => setFormData({...formData, expiryDate: e.target.value})} />
                                        </div>
                                        <div className="col-span-2 pt-6 flex gap-4">
                                            <button type="button" onClick={() => setIsAdding(false)} className="flex-1 py-5 bg-slate-100 rounded-3xl font-black">Cancel</button>
                                            <button type="submit" className="flex-[2] py-5 bg-blue-600 text-white rounded-3xl font-black">Save Stock</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            );
        }

        const root = ReactDOM.createRoot(document.getElementById('root'));
        root.render(<App />);
    </script>
</body>
</html>