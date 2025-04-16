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
    headless: true,
    slowMo: 100,
    executablePath: '/usr/bin/chromium-browser',
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  }); //remover executablePath

  const page = await browser.newPage();
  const BPS_URL = "https://scp.bps.gub.uy/PortalServLineaWeb";

  const empresaId = data.company_number.toString();
  const documento = data.ci.toString();
  const fechaSubsidio = new Date(data.subsid_dateo);
  const udt = calcularUltimoDiaHabil(fechaSubsidio);
  const ocupacion = data.ocupation_code.toString();

  console.log(`📌 Procesando empresa ${empresaId}, documento ${documento}`);

  try {
    await page.goto(BPS_URL, { waitUntil: "domcontentloaded" });

    // 1. Iniciar sesión
    await page.type("#username", credentials.user);
    await page.type("#password", credentials.password);
    await guardarPaso("debug-paso1", page);

    await page.click('input[type="submit"][value="Ingresar"]');

    await page.waitForNavigation();

    // 2. Ir a "Ingresar UDT"
    await page.goto("https://scp.bps.gub.uy/PortalServLineaWeb/serv_emb?escr=TODOS&srvext=9163", { waitUntil: "networkidle2", timeout: 60000 });

    await page.waitForFunction('document.readyState === "complete"', { timeout: 60000 });
    await guardarPaso("debug-paso2", page);

    const frames = page.frames();
    const udtFrame = frames.find(frame => frame.url().includes("SenfAltaUDTRemunera"));
    if (!udtFrame) {
      console.log("❌ No se encontró el iframe de UDT");
      await browser.close();
      return;
    }

    // 3. Seleccionar empresa
    await udtFrame.waitForSelector("#datosIngresoUDTRemuneras", { visible: true, timeout: 60000 });
    await udtFrame.click("#idselEmpresa");
    await udtFrame.waitForSelector('#selEmpresa', { visible: true, timeout: 10000 });
    await udtFrame.type("#NroEmpresa", empresaId);
    await guardarPaso("debug-paso3", page);
    await udtFrame.evaluate(() => {
      document.querySelector("#formListaEmpresas").submit();
    });
    await udtFrame.waitForSelector("#tableEmpresas", { visible: true, timeout: 10000 });
    await guardarPaso("debug-paso4", page);

    await udtFrame.evaluate(() => {
      const label = document.querySelector('label.radioLabel');
      if (label) {
        label.click(); // clic en el label selecciona el radio y posiblemente ejecuta runScript
      }
    });
    
    await guardarPaso("debug-paso5", page);

    // 4. Seleccionar persona
    await udtFrame.waitForSelector('#idSelPersona', { visible: true, timeout: 10000 });
    await guardarPaso("debug-paso6", page);
    await udtFrame.click("#idSelPersona");
    await guardarPaso("debug-paso6", page);
    await udtFrame.waitForSelector('#selPersona', { visible: true, timeout: 10000 });
    await udtFrame.type("#NroDocumento", documento);
    await guardarPaso("debug-paso7", page);
    await udtFrame.evaluate(() => {
      document.querySelector("#form1").submit();
    });
    await udtFrame.waitForSelector("#tablePersonas", { visible: true, timeout: 10000 });
    await guardarPaso("debug-paso8", page);
    /*await udtFrame.waitForSelector('#idSelPersona', { visible: true, timeout: 10000 });
    await guardarPaso("debug-paso6", page);*/

    //resto

    // 9. Esperar confirmación
    /*await page.waitForSelector(".mensajeConfirmacion");

    // 10. Tomar screenshot
    const nombreArchivo = `screenshot_${empresaId}_${documento}.png`;
    const ruta = path.join(__dirname, "screenshots", nombreArchivo);
    fs.mkdirSync(path.dirname(ruta), { recursive: true });
    await page.screenshot({ path: ruta });

    console.log(`✔️ Guardado screenshot para ${documento}`);*/
  } catch (error) {
    console.error(`❌ Error con documento ${documento}:`, error.message);
  }

  await browser.close();
  //console.log(JSON.stringify({ success: true }));
}

function calcularUltimoDiaHabil(fecha) {
  const dia = new Date(fecha);
  do {
    dia.setDate(dia.getDate() - 1);
  } while ([0, 6].includes(dia.getDay())); // 0 = domingo, 6 = sábado
  return dia;
}

function formatearFecha(fecha) {
  const dd = String(fecha.getDate()).padStart(2, "0");
  const mm = String(fecha.getMonth() + 1).padStart(2, "0");
  const yyyy = fecha.getFullYear();
  return `${dd}/${mm}/${yyyy}`;
}

cargarUDT();
