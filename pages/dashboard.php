<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}
include __DIR__ . '/../func/db.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/design.css">
</head>
<body>
    <?php include __DIR__ . '/../func/header.php'; ?>
    <div class="container mt-5 pt-4">
        <h1 class="mb-3">Dashboard</h1>
        

        <?php
        // Dashboard stats: number of users, orders, average sales, today's sales
        $numUsers = 0; $numOrders = 0; $avgSales = 0.0; $dailySales = 0.0;
        try {
            $r = $conn->query("SELECT COUNT(*) AS c FROM customer"); if ($r && ($row = $r->fetch_assoc())) $numUsers = (int)$row['c'];
        } catch (Exception $e) { }
        try {
            $r = $conn->query("SELECT COUNT(*) AS c, IFNULL(AVG(total_amount),0) AS avg FROM orders"); if ($r && ($row = $r->fetch_assoc())) { $numOrders = (int)$row['c']; $avgSales = (float)$row['avg']; }
        } catch (Exception $e) { }
        try {
            $r = $conn->query("SELECT IFNULL(SUM(total_amount),0) AS s FROM orders WHERE DATE(order_date) = CURDATE()"); if ($r && ($row = $r->fetch_assoc())) $dailySales = (float)$row['s'];
        } catch (Exception $e) { }
        ?>

        <div class="row dashboard-widgets g-3">
            <div class="col-6 col-md-3">
                <div class="card p-3 h-100">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-success text-white rounded-3 d-flex align-items-center justify-content-center" style="width:54px;height:54px;font-size:1.4rem;"><i class="bi bi-people-fill" aria-hidden="true"></i></div>
                        <div>
                            <div class="text-muted" style="font-size:0.85rem">Users</div>
                            <div class="h5 mb-0"><?= number_format($numUsers) ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card p-3 h-100">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-success text-white rounded-3 d-flex align-items-center justify-content-center" style="width:54px;height:54px;font-size:1.4rem;"><i class="bi bi-receipt-cutoff" aria-hidden="true"></i></div>
                        <div>
                            <div class="text-muted" style="font-size:0.85rem">Orders</div>
                            <div class="h5 mb-0"><?= number_format($numOrders) ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card p-3 h-100">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-success text-white rounded-3 d-flex align-items-center justify-content-center" style="width:54px;height:54px;font-size:1.4rem;"><i class="bi bi-graph-up" aria-hidden="true"></i></div>
                        <div>
                            <div class="text-muted" style="font-size:0.85rem">Average Sales</div>
                            <div class="h5 mb-0">₱<?= htmlspecialchars(number_format((float)$avgSales,2)) ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card p-3 h-100">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-success text-white rounded-3 d-flex align-items-center justify-content-center" style="width:54px;height:54px;font-size:1.4rem;"><i class="bi bi-currency-dollar" aria-hidden="true"></i></div>
                        <div>
                            <div class="text-muted" style="font-size:0.85rem">Daily Sales</div>
                            <div class="h5 mb-0">₱<?= htmlspecialchars(number_format((float)$dailySales,2)) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Analytics charts -->
        <div class="row mt-4 g-3">
            <div class="col-12 col-lg-6">
                <div class="card p-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="fw-semibold">Sales (Last 14 days)</div>
                    </div>
                    <canvas id="chart-daily-sales" height="180"></canvas>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card p-3 mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="fw-semibold">Top Products (30d)</div>
                    </div>
                    <canvas id="chart-top-products" height="180"></canvas>
                </div>
                
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        html, body { height: 100%; }
        /* Dashboard should not scroll — pagination or widgets should fit the viewport */
        body.dashboard-no-scroll { overflow: hidden; }
    </style>
    <script>
        (function(){
            function adjustBodyPadding(){
                var hdr = document.querySelector('header');
                if (!hdr) return;
                try { hdr.style.position = 'fixed'; hdr.style.top = '0px'; hdr.style.left = '0px'; } catch(e){}
                var rect = hdr.getBoundingClientRect();
                var h = rect.height || 0;
                // Keep page non-scrollable per admin preference
                try { document.body.classList.add('dashboard-no-scroll'); } catch(e){}
                document.body.style.paddingTop = h + 'px';
            }
            adjustBodyPadding();
            window.addEventListener('resize', adjustBodyPadding);
            var hdr = document.querySelector('header');
            if (hdr && typeof ResizeObserver !== 'undefined'){
                try { var ro = new ResizeObserver(adjustBodyPadding); ro.observe(hdr);} catch(e){}
            }
        })();
    </script>
    <script>
        // Helper to fetch JSON from our analytics endpoint
        async function fetchAnalytics(action){
            try{
                const res = await fetch('../api/analytics.php?action=' + encodeURIComponent(action));
                return await res.json();
            }catch(e){ console.error(e); return {status:'error',message:e.message}; }
        }

        // Daily sales chart
        (async function(){
            const r = await fetchAnalytics('daily_sales');
            if (r.status !== 'ok') return;
            const labels = r.data.map(x => x.d);
            const data = r.data.map(x => parseFloat(x.s));
            const ctx = document.getElementById('chart-daily-sales');
            if (!ctx) return;
            new Chart(ctx, {
                type: 'line',
                data: { labels, datasets: [{ label: 'Sales', data, borderColor: '#198754', backgroundColor: 'rgba(25,135,84,0.08)', tension:0.25, fill:true }] },
                options: { responsive:true, plugins:{legend:{display:false}} }
            });
        })();

        // Top products chart
        (async function(){
            const r = await fetchAnalytics('top_products');
            const ctx = document.getElementById('chart-top-products');
            if (!ctx) return;
            if (r.status !== 'ok'){
                // show empty chart
                new Chart(ctx, { type:'bar', data:{labels:[],datasets:[{label:'Qty',data:[],backgroundColor:'#198754'}]}, options:{plugins:{legend:{display:false}}}});
                return;
            }
            const labels = r.data.map(x => x.name);
            const data = r.data.map(x => parseInt(x.qty));
            new Chart(ctx, { type:'bar', data:{ labels, datasets:[{ label:'Quantity', data, backgroundColor:'#198754' }] }, options:{ indexAxis:'y', responsive:true, plugins:{legend:{display:false}} } });
        })();
    </script>
</body>
</html>
