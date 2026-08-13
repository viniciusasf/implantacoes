const fs = require('fs');
const path = require('path');

const projectRoot = path.resolve(__dirname, '..');
const cacheDir = process.env.PUPPETEER_CACHE_DIR || path.join(projectRoot, '.cache', 'puppeteer');
process.env.PUPPETEER_CACHE_DIR = cacheDir;
process.env.HOME = process.env.HOME || projectRoot;
process.env.USERPROFILE = process.env.USERPROFILE || projectRoot;

const puppeteer = require('puppeteer');

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

        const browserOptions = {
            headless: 'new',
            args: ['--no-sandbox', '--disable-setuid-sandbox']
        };

        const chromeExecutable = resolveChromeExecutable();
        if (chromeExecutable) {
            browserOptions.executablePath = chromeExecutable;
        }

        const browser = await puppeteer.launch(browserOptions);
        
        const page = await browser.newPage();
        
        // Carrega o arquivo HTML local
        await page.goto(`file://${htmlFilePath.replace(/\\/g, '/')}`, {
            waitUntil: 'networkidle0' // Aguarda até que não haja mais requisições de rede (fontes, imagens)
        });

        // Gera o PDF
        await page.pdf({
            path: pdfFilePath,
            format: 'A4',
            printBackground: true, // Importante para renderizar cores de fundo e gradientes
            margin: {
                top: '0px',
                right: '0px',
                bottom: '0px',
                left: '0px'
            }
        });

        await browser.close();
        console.log('PDF gerado com sucesso em:', pdfFilePath);
        process.exit(0);
    } catch (error) {
        console.error('Erro ao gerar PDF:', error);
        process.exit(1);
    }
})();
