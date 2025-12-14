const puppeteer = require("puppeteer");
const fs = require("fs");
const path = require("path");

const { data, credentials } = JSON.parse(fs.readFileSync(process.argv[2], "utf-8"));
const DEBUG = process.argv[3] === "1";

const screenShotDir = path.join(__dirname, "screenshots");
const logFile = path.join(__dirname, 'udt-debug.log');
fs.mkdirSync(screenShotDir, { recursive: true });

// Error categories for better debugging
const ERROR_TYPES = {
  TIMEOUT: 'timeout',
  NETWORK: 'network',
  WEBSITE_DOWN: 'website_down',
  AUTHENTICATION: 'authentication',
  ELEMENT_NOT_FOUND: 'element_not_found',
  VALIDATION: 'validation',
  UNKNOWN: 'unknown'
};

// Processing steps for tracking
const STEPS = {
  INIT: 'initialization',
  BROWSER_LAUNCH: 'browser_launch',
  LOGIN_PAGE: 'login_page_load',
  LOGIN_SUBMIT: 'login_submit',
  NAVIGATE_UDT: 'navigate_udt_service',
  FRAME_LOAD: 'frame_load',
  COMPANY_SELECT: 'company_selection',
  COMPANY_SEARCH: 'company_search',
  PERSON_SELECT: 'person_selection',
  PERSON_SEARCH: 'person_search',
  SOLICITUD_SELECT: 'solicitud_selection',
  FORM_FILL: 'form_fill',
  SUBMIT_UDT: 'submit_udt',
  CONFIRMATION: 'confirmation',
  CLEANUP: 'cleanup'
};

let currentStep = STEPS.INIT;
let stepTimings = {};
let stepStartTime = Date.now();

function logToFile(message, data = null) {
  const timestamp = new Date().toISOString();
  let logEntry = `[${timestamp}] [${currentStep}] ${message}`;
  if (data) {
    logEntry += ` | Data: ${JSON.stringify(data)}`;
  }
  fs.appendFileSync(logFile, logEntry + '\n');
  
  if (DEBUG) {
    console.log(logEntry);
  }
}

function setStep(step) {
  const now = Date.now();
  if (currentStep) {
    stepTimings[currentStep] = now - stepStartTime;
  }
  currentStep = step;
  stepStartTime = now;
  logToFile(`Starting step: ${step}`);
}

function saveStep(name, page) {
  const dir = path.join(screenShotDir, `${name}.png`);
  logToFile(`Saving screenshot: ${name}`);
  return page.screenshot({ path: dir, fullPage: true });
}

function categorizeError(error) {
  const message = error.message.toLowerCase();
  
  if (message.includes('timeout') || message.includes('timed out') || message.includes('navigation timeout')) {
    return { type: ERROR_TYPES.TIMEOUT, isRetryable: true };
  }
  if (message.includes('net::') || message.includes('network') || message.includes('connection') || message.includes('dns')) {
    return { type: ERROR_TYPES.NETWORK, isRetryable: true };
  }
  if (message.includes('503') || message.includes('502') || message.includes('500') || message.includes('service unavailable')) {
    return { type: ERROR_TYPES.WEBSITE_DOWN, isRetryable: true };
  }
  if (message.includes('login') || message.includes('credential') || message.includes('auth') || message.includes('401') || message.includes('403')) {
    return { type: ERROR_TYPES.AUTHENTICATION, isRetryable: false };
  }
  if (message.includes('no element') || message.includes('not found') || message.includes('selector') || message.includes('frame')) {
    return { type: ERROR_TYPES.ELEMENT_NOT_FOUND, isRetryable: true };
  }
  if (message.includes('valid') || message.includes('invalid')) {
    return { type: ERROR_TYPES.VALIDATION, isRetryable: false };
  }
  
  return { type: ERROR_TYPES.UNKNOWN, isRetryable: true };
}

function createErrorResponse(error, additionalInfo = {}) {
  const errorCategory = categorizeError(error);
  
  return {
    error: error.message,
    errorType: errorCategory.type,
    isRetryable: errorCategory.isRetryable,
    step: currentStep,
    stepTimings: stepTimings,
    stack: error.stack,
    timestamp: new Date().toISOString(),
    personId: data.id,
    personCi: data.ci,
    companyNumber: data.company_number,
    ...additionalInfo
  };
}

async function waitWithRetry(page, selector, options = {}, maxRetries = 3) {
  const { timeout = 60000, visible = true } = options;
  
  for (let attempt = 1; attempt <= maxRetries; attempt++) {
    try {
      logToFile(`Waiting for selector: ${selector} (attempt ${attempt}/${maxRetries})`);
      await page.waitForSelector(selector, { timeout, visible });
      return true;
    } catch (error) {
      logToFile(`Selector wait failed: ${selector}`, { attempt, error: error.message });
      if (attempt === maxRetries) throw error;
      await new Promise(r => setTimeout(r, 2000 * attempt)); // Exponential backoff
    }
  }
}

async function loadUDTProcess() {
  let browser = null;
  let page = null;
  
  const processStartTime = Date.now();
  
  try {
    setStep(STEPS.BROWSER_LAUNCH);
    
    // Determine executable path based on environment
    const executablePath = process.env.PUPPETEER_EXECUTABLE_PATH || '/usr/bin/chromium-browser';
    
    logToFile('Launching browser', { executablePath });
    
    browser = await puppeteer.launch({
      headless: true,
      slowMo: 100,
      executablePath: executablePath,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-accelerated-2d-canvas',
        '--disable-gpu',
        '--window-size=1280,800'
      ]
    });

    page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 800 });
    
    // Set up request interception for network monitoring
    await page.setRequestInterception(true);
    let networkErrors = [];
    
    page.on('request', request => {
      request.continue();
    });
    
    page.on('requestfailed', request => {
      networkErrors.push({
        url: request.url(),
        failure: request.failure()?.errorText,
        timestamp: new Date().toISOString()
      });
      logToFile('Request failed', { url: request.url(), error: request.failure()?.errorText });
    });
    
    page.on('console', msg => {
      if (DEBUG) {
        logToFile(`Browser console: ${msg.type()}: ${msg.text()}`);
      }
    });

    // [1] LOGIN FLOW
    setStep(STEPS.LOGIN_PAGE);
    logToFile('Navigating to login page');
    
    const loginResponse = await page.goto("https://scp.bps.gub.uy/PortalServLineaWeb", {
      waitUntil: "networkidle2",
      timeout: 120000
    });
    
    if (!loginResponse.ok()) {
      throw new Error(`Login page returned status ${loginResponse.status()}`);
    }
    
    logToFile('Login page loaded', { status: loginResponse.status() });
    
    await page.type("#username", credentials.user, { delay: 30 });
    await page.type("#password", credentials.password, { delay: 30 });
    await saveStep("1-login-form", page);
    
    setStep(STEPS.LOGIN_SUBMIT);
    logToFile('Submitting login form');
    
    await Promise.all([
      page.click('input[type="submit"][value="Ingresar"]'),
      page.waitForNavigation({ waitUntil: "networkidle2", timeout: 120000 })
    ]);
    
    // Verify login success
    const currentUrl = page.url();
    if (currentUrl.includes('error') || currentUrl.includes('login')) {
      await saveStep("login-failed", page);
      throw new Error('Login failed - possibly invalid credentials or session issue');
    }
    
    logToFile('Login successful', { redirectUrl: currentUrl });

    // [2] NAVIGATE TO UDT SERVICE
    setStep(STEPS.NAVIGATE_UDT);
    logToFile('Navigating to UDT service');
    
    const udtResponse = await page.goto("https://scp.bps.gub.uy/PortalServLineaWeb/serv_emb?escr=TODOS&srvext=9163", {
      waitUntil: "networkidle2",
      timeout: 120000
    });
    
    if (!udtResponse.ok()) {
      throw new Error(`UDT service page returned status ${udtResponse.status()}`);
    }
    
    logToFile('UDT service page loaded', { status: udtResponse.status() });

    // [3] FRAME HANDLING
    setStep(STEPS.FRAME_LOAD);
    logToFile('Waiting for UDT frame');
    
    const getUDTFrame = async (retries = 5) => {
      for (let i = 0; i < retries; i++) {
        try {
          await page.waitForFunction(() => {
            const iframe = document.querySelector('iframe');
            return iframe && iframe.contentDocument && iframe.contentDocument.readyState === 'complete';
          }, { timeout: 60000 });
          
          const frames = await page.frames();
          const udtFrame = frames.find(f => f.url().includes("SenfAltaUDTRemunera"));
          
          if (udtFrame) {
            logToFile('UDT frame found', { frameUrl: udtFrame.url() });
            return udtFrame;
          }
        } catch (error) {
          logToFile(`Frame search attempt ${i + 1} failed`, { error: error.message });
          if (i === retries - 1) throw error;
          await new Promise(r => setTimeout(r, 3000));
        }
      }
      throw new Error("UDT frame not found after multiple attempts");
    };

    let udtFrame = await getUDTFrame();

    // [4] COMPANY SELECTION
    setStep(STEPS.COMPANY_SELECT);
    logToFile('Starting company selection');
    
    await udtFrame.waitForSelector("#idselEmpresa", { visible: true, timeout: 60000 });
    await udtFrame.click("#idselEmpresa");
    
    setStep(STEPS.COMPANY_SEARCH);
    await udtFrame.waitForSelector('#NroEmpresa', { visible: true, timeout: 30000 });
    await udtFrame.type("#NroEmpresa", data.company_number.toString(), { delay: 50 });
    await saveStep("2-company-input", page);
    
    logToFile('Company number entered', { companyNumber: data.company_number });

    udtFrame.click('button.btnGreen.mobile-Left');
  
    await udtFrame.waitForFunction(() => {
      const rows = document.querySelectorAll('#tableEmpresas tbody tr');
      return rows.length > 1;
    }, { timeout: 60000 });

    await saveStep("3-company-table-loaded", page);
    logToFile('Company table loaded');

    // [5] RADIO BUTTON SELECTION
    await udtFrame.evaluate(() => {
      const label = document.querySelector('label.radioLabel');
      if (label) {
        label.click();
      }
    });
    await saveStep("4-radio-selected", page);
    logToFile('Company radio selected');

    // [6] POPUP HANDLING
    let popupClose = await page.$('a.fancybox-close');
    if (popupClose) {
      await popupClose.click();
      logToFile('Popup closed');
    }

    await saveStep("5-post-submission", page);

    // [7] PERSON SELECTION
    setStep(STEPS.PERSON_SELECT);
    udtFrame = await getUDTFrame();
    await udtFrame.waitForSelector('#idSelPersona', { visible: true, timeout: 60000 });
    await udtFrame.click("#idSelPersona");
    
    setStep(STEPS.PERSON_SEARCH);
    await udtFrame.type("#NroDocumento", data.ci.toString(), { delay: 50 });
    await saveStep("6-person-input", page);
    
    logToFile('Person CI entered', { ci: data.ci });

    udtFrame.click('#btnObtenerPersona');
    await new Promise(res => setTimeout(res, 1500));

    // [7.1] CHECK FOR MULTIPLE SOLICITUDES
    setStep(STEPS.SOLICITUD_SELECT);
    logToFile('Checking for multiple solicitudes');
    
    const resultSelector = await Promise.race([
      udtFrame.waitForSelector('#tableSolicitudesSinUDT', { visible: true, timeout: 60000 }).then(() => 'solicitudes'),
      udtFrame.waitForSelector(
        '#divDatos .listLabelLg:not([disabled]):not(.disabled)',
        { visible: true, timeout: 60000 }
      ).then(() => 'divDatos')
    ]);

    if (resultSelector === 'solicitudes') {
      logToFile('Multiple solicitudes found, selecting correct one');

      const solicitudId = data.id?.toString();

      const solicitudFound = await udtFrame.evaluate((solicitudId) => {
        const radios = Array.from(document.querySelectorAll('input[type="radio"][name="SolicIdMovil"]'));
        for (const radio of radios) {
          if (radio.id?.includes(solicitudId)) {
            radio.click();
            return { found: true, selectedId: solicitudId };
          }
        }

        // Fallback: select first available
        const firstRadio = radios[0];
        if (firstRadio) {
          firstRadio.click();
          return { found: false, selectedId: firstRadio.id };
        }

        return { found: false, selectedId: null };
      }, solicitudId);

      logToFile('Solicitud selection result', solicitudFound);
      await saveStep("6.1-solicitud-seleccionada", page);

    } else {
      logToFile('Direct to divDatos - no multiple solicitudes');
    }

    // [8] FORM FILLING
    setStep(STEPS.FORM_FILL);
    await udtFrame.waitForSelector(
      '#divDatos .listLabelLg:not([disabled]):not(.disabled)',
      { timeout: 60000 }
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

    logToFile('Subsidy start date found', { subsidStartDate });

    const lastBusinessDay = getLastBusinessDay(subsidStartDate);
    await udtFrame.type('input[name="FechaUDT"]', lastBusinessDay, { delay: 50 });
    
    logToFile('UDT date entered', { lastBusinessDay });

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

    logToFile('Occupation selected', { ocupation: credentials.ocupation });

    await saveStep("8-prev-confirmation", page);

    // [9] SUBMIT UDT
    setStep(STEPS.SUBMIT_UDT);
    logToFile('Submitting UDT');
    
    await udtFrame.click('#btnIngresarUDT');
    
    setStep(STEPS.CONFIRMATION);
    await udtFrame.waitForSelector('#altaUDTRemuneraExito', { visible: true, timeout: 120000 });
    
    await udtFrame.evaluate(() => {
      const el = document.querySelector('#altaUDTRemuneraExito');
      if (el) el.scrollIntoView({ behavior: "smooth", block: "center" });
    });

    await new Promise(res => setTimeout(res, 500));

    await saveStep("UDT-" + data.id.toString(), page);
    
    logToFile('UDT process completed successfully');

    // Calculate total processing time
    const totalTime = Date.now() - processStartTime;
    stepTimings['total'] = totalTime;

    // Output success response
    const successResponse = {
      success: true,
      status: 'ok',
      personId: data.id,
      personCi: data.ci,
      companyNumber: data.company_number,
      udtDate: lastBusinessDay,
      stepTimings: stepTimings,
      totalTimeMs: totalTime,
      timestamp: new Date().toISOString()
    };

    console.log(JSON.stringify(successResponse));

  } catch (error) {
    logToFile(`CRITICAL ERROR: ${error.message}`, { stack: error.stack });
    
    // Save error screenshot
    if (page) {
      try {
        await saveStep("error-screen-" + data.id.toString(), page);
      } catch (screenshotError) {
        logToFile('Failed to save error screenshot', { error: screenshotError.message });
      }
    }
    
    // Output detailed error response
    const errorResponse = createErrorResponse(error, {
      totalTimeMs: Date.now() - processStartTime
    });
    
    console.log(JSON.stringify(errorResponse));
    process.exit(1);
    
  } finally {
    setStep(STEPS.CLEANUP);
    
    if (browser) {
      try {
        await browser.close();
        logToFile('Browser closed');
      } catch (closeError) {
        logToFile('Error closing browser', { error: closeError.message });
      }
    }
  }
}

function getLastBusinessDay(dateStr, holidays = []) {
  // Convert from "DD/MM/YYYY" to Date
  const [day, month, year] = dateStr.split('/').map(Number);
  const date = new Date(year, month - 1, day);

  // Go back until we find a business day
  while (
    date.getDay() === 0 || // Sunday
    date.getDay() === 6 || // Saturday
    holidays.includes(formatDate(date))
  ) {
    date.setDate(date.getDate() - 1);
  }

  return formatDate(date);
}

function formatDate(date) {
  const day = String(date.getDate()).padStart(2, '0');
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const year = date.getFullYear();
  return `${day}/${month}/${year}`;
}

loadUDTProcess();
