const puppeteer = require("puppeteer");
const fs = require("fs");
const path = require("path");

const { data, credentials } = JSON.parse(fs.readFileSync(process.argv[2], "utf-8"));

const screenShotDir = path.join(__dirname, "screenshots");
fs.mkdirSync(screenShotDir, { recursive: true });

function saveStep(name, page) {
  const dir = path.join(screenShotDir, `${name}.png`);
  return page.screenshot({ path: dir });
}

async function loadUDTProcess() {
  const browser = await puppeteer.launch({
    headless: true,
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
    await saveStep("1-login-form", page);
    
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
    await saveStep("2-company-input", page);

    udtFrame.click('button.btnGreen.mobile-Left')
  
    await udtFrame.waitForFunction(() => {
      const rows = document.querySelectorAll('#tableEmpresas tbody tr');
      console.log(rows)
      return rows.length > 1;
    }, { timeout: 30000 });

    await saveStep("3-company-table-loaded", page);

    // [5] Radio Button Handling with DOM Verification
    await udtFrame.evaluate(() => {
      const label = document.querySelector('label.radioLabel');
      if (label) {
        label.click();
      }
    });
    await saveStep("4-radio-selected", page);

    // [6] Final Submission with Popup Handling
    let popupClose = await page.$('a.fancybox-close');
    if (popupClose) {
      await popupClose.click();
    }

    await saveStep("5-post-submission", page);

    // [7] Person Selection with Fresh Frame Reference
    udtFrame = await getUDTFrame();
    await udtFrame.waitForSelector('#idSelPersona', { visible: true, timeout: 30000 });
    await udtFrame.click("#idSelPersona");
    
    await udtFrame.type("#NroDocumento", data.ci.toString(), { delay: 50 });
    await saveStep("6-person-input", page);

    udtFrame.click('#btnObtenerPersona');

    await udtFrame.waitForSelector(
      '#divDatos .listLabelLg:not([disabled]):not(.disabled)',
      { timeout: 30000 }
    );

    const subsidStartDate = await udtFrame.evaluate(() => {
      const items = Array.from(document.querySelectorAll("#divDatos ul.listLabelLg li"));
      for (const item of items) {
        const label = item.querySelector("label")?.textContent?.trim();
        if (label?.includes("Inicio del subsidio:")) {
          return item.querySelector("span")?.textContent?.trim() || null;
        }
      }
      return null;
    });

    const lastBusinessDay = getLastBusinessDay(subsidStartDate);
    await udtFrame.type('input[name="FechaUDT"]', lastBusinessDay, { delay: 50 });

    await udtFrame.evaluate(() => {
      const el = document.querySelector('input[name="FechaUDT"]');
      if (el) el.scrollIntoView({ behavior: "smooth", block: "center" });
    });

    await saveStep("7-final-screen", page);

    await udtFrame.evaluate(() => {
      const el = document.querySelector('#btnIngresarUDT');
      if (el) el.scrollIntoView({ behavior: "smooth", block: "center" });
    });

    await udtFrame.evaluate((ocupation) => {
      const select = document.querySelector('#combobox');
      if (select) {
        const optionToSelect = Array.from(select.options).find(opt =>
          opt.value.trim().startsWith(ocupation.toString())
        );
        if (optionToSelect) {
          select.value = optionToSelect.value;
          select.dispatchEvent(new Event('change', { bubbles: true }));
        }
      }
    }, credentials.ocupation);

    await saveStep("8-prev-confirmation", page);

    await udtFrame.click('#btnIngresarUDT');
    await udtFrame.waitForSelector('#altaUDTRemuneraExito', { visible: true, timeout: 30000 });
    await udtFrame.evaluate(() => {
      const el = document.querySelector('#altaUDTRemuneraExito');
      if (el) el.scrollIntoView({ behavior: "smooth", block: "center" });
    });

    await new Promise(res => setTimeout(res, 500));

    await saveStep("UDT-"+data.id.toString(), page);

  } catch (error) {
    console.error(`❌ Critical error: ${error.message}`);
    await saveStep("error-screen", page);
    throw error;
  } finally {
    await browser.close();
  }
}

function getLastBusinessDay(dateStr, holidays = []) {
  // Convertir de "DD/MM/YYYY" a Date
  const [day, month, year] = dateStr.split('/').map(Number);
  const date = new Date(year, month - 1, day); // Mes empieza en 0

  // Retroceder hasta encontrar un día hábil
  while (
    date.getDay() === 0 || // domingo
    date.getDay() === 6 || // sábado
    holidays.includes(formatDate(date))
  ) {
    date.setDate(date.getDate() - 1);
  }

  return formatDate(date);
}

function formatDate(date) {
  const day = String(date.getDate()).padStart(2, '0');
  const month = String(date.getMonth() + 1).padStart(2, '0'); // Mes empieza en 0
  const year = date.getFullYear();
  return `${day}/${month}/${year}`;
}

loadUDTProcess();