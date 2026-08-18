const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');

const projectRoot = path.resolve(__dirname, '..');
const cacheDir = process.env.PUPPETEER_CACHE_DIR || path.join(projectRoot, '.cache', 'puppeteer');
process.env.PUPPETEER_CACHE_DIR = cacheDir;
process.env.HOME = process.env.HOME || projectRoot;
process.env.USERPROFILE = process.env.USERPROFILE || projectRoot;

const puppeteer = require('puppeteer');

function createUniqueUserDataDir() {
    const tmpBaseDir = path.join(projectRoot, 'tmp_puppeteer_profiles');
    fs.mkdirSync(tmpBaseDir, { recursive: true });

    const uniqueId = `${Date.now()}_${process.pid}_${Math.random().toString(16).slice(2)}`;
    const userDataDir = path.join(tmpBaseDir, uniqueId);
    fs.mkdirSync(userDataDir, { recursive: true });

    return userDataDir;
}

function killStaleChromeForTempProfile(userDataDir) {
    if (process.platform !== 'win32' || !userDataDir) {
        return;
    }

    const script = `
        $procs = Get-CimInstance Win32_Process -Filter "name='chrome.exe'" |
            Where-Object { $_.CommandLine -match 'tmp_puppeteer_profiles' };
        foreach ($p in $procs) {
            try {
                Stop-Process -Id $p.ProcessId -Force -ErrorAction Stop;
            } catch {}
        }
    `;

    spawnSync('powershell', [
        '-NoProfile',
        '-ExecutionPolicy',
        'Bypass',
        '-Command',
        script
    ], {
        stdio: 'ignore',
        windowsHide: true
    });
}

async function removeDirWithRetry(dirPath, retries = 8) {
    for (let attempt = 0; attempt < retries; attempt++) {
        try {
            fs.rmSync(dirPath, { recursive: true, force: true });
            return;
        } catch (err) {
            const retryable = ['EPERM', 'EBUSY', 'EACCES'].includes(err && err.code);
            if (!retryable || attempt === retries - 1) {
                throw err;
            }
            await new Promise(resolve => setTimeout(resolve, 250 * (attempt + 1)));
        }
    }
}

function walkForChrome(currentDir, found) {
    if (!fs.existsSync(currentDir)) {
        return found;
    }

    for (const entry of fs.readdirSync(currentDir, { withFileTypes: true })) {
        const fullPath = path.join(currentDir, entry.name);

        if (entry.isDirectory()) {
            const recursiveResult = walkForChrome(fullPath, found);
            if (recursiveResult) {
                return recursiveResult;
            }
            continue;
        }

        if (entry.isFile() && (entry.name.toLowerCase() === 'chrome.exe' || entry.name.toLowerCase() === 'chrome')) {
            return fullPath;
        }
    }

    return found;
}

function resolveChromeExecutable() {
    const candidates = [
        process.env.PUPPETEER_EXECUTABLE_PATH,
        process.env.CHROME_BIN,
        process.env.GOOGLE_CHROME_BIN,
        process.env.CHROMIUM_BIN,
        path.join(projectRoot, 'chrome', 'win64-151.0.7922.77', 'chrome-win64', 'chrome.exe'),
        path.join(projectRoot, 'chrome', 'win64', 'chrome-win64', 'chrome.exe'),
        path.join(projectRoot, 'chrome', 'chrome-win64', 'chrome.exe'),
        path.join(projectRoot, '.cache', 'puppeteer', 'chrome', 'win64-151.0.7922.77', 'chrome-win64', 'chrome.exe'),
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files\\Chromium\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Chromium\\Application\\chrome.exe'
    ].filter(Boolean);

    for (const candidate of candidates) {
        if (candidate && fs.existsSync(candidate)) {
            return candidate;
        }
    }

    for (const baseDir of [projectRoot, path.join(projectRoot, '.cache'), path.join(projectRoot, '.cache', 'puppeteer')]) {
        const found = walkForChrome(baseDir, null);
        if (found) {
            return found;
        }
    }

    return null;
}

(async () => {
    try {
        const args = process.argv.slice(2);
        if (args.length < 2) {
            console.error('Uso: node html_to_pdf.js <arquivo_html_entrada> <arquivo_pdf_saida>');
            process.exit(1);
        }

        const htmlFilePath = path.resolve(args[0]);
        const pdfFilePath = path.resolve(args[1]);

        if (!fs.existsSync(htmlFilePath)) {
            console.error('Arquivo HTML não encontrado:', htmlFilePath);
            process.exit(1);
        }

        const userDataDir = createUniqueUserDataDir();
        killStaleChromeForTempProfile(userDataDir);

        const browserOptions = {
            headless: 'new',
            executablePath: resolveChromeExecutable() || undefined,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--disable-software-rasterizer',
                '--user-data-dir=' + userDataDir
            ]
        };

        let browser;
        try {
            browser = await puppeteer.launch(browserOptions);

            const page = await browser.newPage();

            await page.goto(`file://${htmlFilePath.replace(/\\/g, '/')}`, {
                waitUntil: 'networkidle0'
            });

            await page.pdf({
                path: pdfFilePath,
                format: 'A4',
                printBackground: true,
                margin: {
                    top: '0px',
                    right: '0px',
                    bottom: '0px',
                    left: '0px'
                }
            });

            console.log('PDF gerado com sucesso em:', pdfFilePath);
            process.exit(0);
        } finally {
            if (browser) {
                try {
                    await browser.close();
                } catch (closeError) {
                    console.error('Erro ao fechar navegador:', closeError);
                }
            }

            try {
                killStaleChromeForTempProfile(userDataDir);
                await removeDirWithRetry(userDataDir);
            } catch (cleanupError) {
                console.error('Erro ao limpar perfil do Puppeteer:', cleanupError);
            }
        }
    } catch (error) {
        console.error('Erro ao gerar PDF:', error);
        process.exit(1);
    }
})();
