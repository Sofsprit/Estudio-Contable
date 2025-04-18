const puppeteer = require("puppeteer");
const fs = require("fs");
const path = require("path");

const rutaJson = process.argv[2];
const { data, credentials } = JSON.parse(fs.readFileSync(rutaJson, "utf-8"));

const rutaScreenshots = path.join(__dirname, "screenshots");
fs.mkdirSync(rutaScreenshots, { recursive: true });

function guardarPaso(nombre, page) {
  const ruta = path.join(rutaScreenshots, `${nombre}.png`);
  return page.screenshot({ path: ruta });
}

async function cargarUDT() {
  const browser = await puppeteer.launch({
    headless: true, // Keep false for debugging
    slowMo: 100,
    executablePath: '/usr/bin/chromium-browser',
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });

  const page = await browser.newPage();
  await page.setViewport({ width: 1280, height: 800 });

  try {
    // [1] Enhanced Login Flow
    await page.goto("https://scp.bps.gub.uy/PortalServLineaWeb", {
      waitUntil: "networkidle2",
      timeout: 60000
    });
    
    await page.type("#username", credentials.user);
    await page.type("#password", credentials.password);
    await guardarPaso("1-login-form", page);
    
    await Promise.all([
      page.click('input[type="submit"][value="Ingresar"]'),
      page.waitForNavigation({ waitUntil: "networkidle2", timeout: 30000 })
    ]);

    // [2] Navigate with Frame Monitoring
    await page.goto("https://scp.bps.gub.uy/PortalServLineaWeb/serv_emb?escr=TODOS&srvext=9163", {
      waitUntil: "networkidle2",
      timeout: 60000
    });

    // [3] Robust Frame Handling
    const getUDTFrame = async () => {
      await page.waitForFunction(() => {
        return document.querySelector('iframe')?.contentDocument?.readyState === 'complete';
      }, { timeout: 30000 });
      
      const frames = await page.frames();
      return frames.find(f => f.url().includes("SenfAltaUDTRemunera"));
    };

    let udtFrame = await getUDTFrame();
    if (!udtFrame) throw new Error("UDT frame not found");

    // [4] Company Selection with Stability
    await udtFrame.waitForSelector("#idselEmpresa", { visible: true, timeout: 30000 });
    await udtFrame.click("#idselEmpresa");
    
    await udtFrame.waitForSelector('#NroEmpresa', { visible: true });
    await udtFrame.type("#NroEmpresa", data.company_number.toString(), { delay: 50 });
    await guardarPaso("2-company-input", page);

    // Submit with frame reload handling
    udtFrame.click('button.btnGreen.mobile-Left')
  
    await udtFrame.waitForFunction(() => {
      const rows = document.querySelectorAll('#tableEmpresas tbody tr');
      console.log(rows)
      return rows.length > 1;
    }, { timeout: 30000 });

    await guardarPaso("3-company-table-loaded", page);

    // [5] Radio Button Handling with DOM Verification
    await udtFrame.evaluate(() => {
      const label = document.querySelector('label.radioLabel');
      if (label) {
        label.click(); // clic en el label selecciona el radio y posiblemente ejecuta runScript
      }
    });
    await guardarPaso("4-radio-selected", page);

    // [6] Final Submission with Popup Handling
    let popupClose = await page.$('a.fancybox-close');
    if (popupClose) {
      await popupClose.click();
    }

    await guardarPaso("5-post-submission", page);

    // [7] Person Selection with Fresh Frame Reference
    udtFrame = await getUDTFrame();
    await udtFrame.waitForSelector('#idSelPersona', { visible: true, timeout: 30000 });
    await udtFrame.click("#idSelPersona");
    
    await udtFrame.type("#NroDocumento", data.ci.toString(), { delay: 50 });
    await guardarPaso("6-person-input", page);

    udtFrame.click('#btnObtenerPersona');

    await udtFrame.waitForSelector(
      '#divDatos .listLabelLg:not([disabled]):not(.disabled)',
      { timeout: 30000 }
    );

    await guardarPaso("7-final-screen", page);

  } catch (error) {
    console.error(`❌ Error crítico: ${error.message}`);
    await guardarPaso("error-screen", page);
    throw error;
  } finally {
    await browser.close();
  }
}

// Rest of utility functions remain the same
cargarUDT();