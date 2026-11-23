from pathlib import Path
from playwright.sync_api import sync_playwright

root = Path(__file__).resolve().parents[2]
css = (root / 'core/shared-dashboard.css').read_text()
html = f'''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>
:root{{--fams-line:#dfe7ee;--fams-ink:#26384a;--fams-navy:#173b63;--fams-muted:#718699;}}
*{{box-sizing:border-box}}body{{margin:0;padding:20px;background:#f4f7fa;font-family:Arial,sans-serif}}
.panel{{padding:18px;border:1px solid #dfe7ee;border-radius:14px;background:white;}}
.dashboard-layout-grid{{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:14px}}
.dashboard-layout-item{{grid-column:1/-1;min-width:0}}
.dashboard-inner-editor-grid{{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:12px}}
.dashboard-editor-division{{grid-column:span 6;min-width:0}}
.dashboard-period{{display:flex;flex-direction:column;gap:5px;padding:14px 16px;border:1px solid #e1e8ef;border-radius:11px;background:#f9fbfd;min-height:120px}}
.dashboard-period strong{{font-size:22px}}.dashboard-period small{{font-size:10px;line-height:1.5;color:#718398}}
.secondary-btn{{border:1px solid #dfe7ee;border-radius:9px;background:white;color:#28547d;font-size:12px;font-weight:bold;text-decoration:none}}
{css}
</style></head><body><div id="dashboardLayout" class="dashboard-layout-grid fams-dashboard"><section class="dashboard-layout-item" data-dashboard-item="reporting-filter"><section class="dashboard-filter panel reporting-period-panel"><div class="reporting-editor-grid"><div class="dashboard-editor-division"><form class="dashboard-filter-form"><div><label>Reporting Year</label><select><option>2026</option></select></div><div><label>Reporting Month</label><select><option>November</option></select></div></form></div><div class="dashboard-editor-division"><div class="dashboard-period"><span class="dashboard-period-kicker">SELECTED REPORTING PERIOD</span><strong>November / 2026</strong><small>Daily records reconciled with monthly summary. This supporting text is deliberately long enough to wrap across smaller screens.</small><div class="dashboard-period-actions"><a class="secondary-btn" href="#">Display Selected Period Report</a></div></div></div></div></section></section></div></body></html>'''

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, executable_path='/usr/bin/chromium', args=['--no-sandbox'])
    for width, height, name in [(1440,1000,'desktop'),(1024,900,'tablet-wide'),(768,1024,'tablet'),(390,844,'mobile'),(360,800,'small-mobile')]:
        page = browser.new_page(viewport={"width":width,"height":height}, device_scale_factor=1)
        page.set_content(html, wait_until='load')
        page.screenshot(path=str(root / f'maintenance/visual-baseline-reporting-{name}-v0.0195.png'), full_page=True)
        metrics = page.evaluate('''() => {const c=document.querySelector('.dashboard-period');const b=document.querySelector('.dashboard-period-actions a');const r=c.getBoundingClientRect(),q=b.getBoundingClientRect();return {scrollWidth:document.documentElement.scrollWidth,innerWidth,cardRight:r.right,buttonRight:q.right,buttonLeft:q.left,cardLeft:r.left,buttonInside:q.right<=r.right+1&&q.left>=r.left-1,buttonAtRight:Math.abs((r.right-q.right)-parseFloat(getComputedStyle(c).paddingRight))<30}}''')
        assert metrics['scrollWidth'] <= width, f'{name}: horizontal overflow {metrics}'
        assert metrics['buttonInside'], f'{name}: button escapes card {metrics}'
        assert metrics['buttonAtRight'], f'{name}: button not right-aligned {metrics}'
        print(f'PASS {name} {width}x{height}: {metrics}')
        page.close()
    browser.close()
