import fs from 'node:fs';
import path from 'node:path';
import ts from 'typescript';

const root = process.cwd();
const dist = path.join(root, 'dist');
fs.rmSync(dist, { recursive: true, force: true });
fs.mkdirSync(dist, { recursive: true });

const transpile = (input, output) => {
  const source = fs.readFileSync(path.join(root, input), 'utf8');
  const result = ts.transpileModule(source, {
    fileName: input,
    reportDiagnostics: true,
    compilerOptions: {
      target: ts.ScriptTarget.ES2020,
      module: ts.ModuleKind.ESNext,
      jsx: ts.JsxEmit.ReactJSX,
      strict: true,
      esModuleInterop: true,
      allowSyntheticDefaultImports: true,
    },
  });
  const errors = (result.diagnostics || []).filter((item) => item.category === ts.DiagnosticCategory.Error);
  if (errors.length) throw new Error(ts.formatDiagnosticsWithColorAndContext(errors, {
    getCurrentDirectory: () => root,
    getCanonicalFileName: (name) => name,
    getNewLine: () => '\n',
  }));
  let js = result.outputText;
  if (input.endsWith('main.tsx')) {
    js = js.replace(/import ['"]\.\/style\.css['"];?\s*/g, '').replace(/from ['"]\.\/App['"]/g, "from './App.js'");
  }
  if (input.endsWith('App.tsx')) {
    js = js.replaceAll('import.meta.env.BASE_URL', '"/credit-card/"').replaceAll('import.meta.env.PROD', 'true');
  }
  fs.writeFileSync(path.join(dist, output), js);
};

transpile('frontend/src/App.tsx', 'App.js');
transpile('frontend/src/main.tsx', 'main.js');
fs.copyFileSync(path.join(root, 'frontend/src/style.css'), path.join(dist, 'style.css'));
fs.cpSync(path.join(root, 'public'), dist, { recursive: true });
fs.writeFileSync(path.join(dist, 'index.html'), `<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#09090b">
  <meta name="description" content="A private, mobile-first credit card expense and billing-cycle manager.">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <link rel="manifest" href="./manifest.webmanifest">
  <link rel="icon" type="image/png" sizes="192x192" href="./icons/icon-192.png">
  <link rel="apple-touch-icon" href="./icons/icon-192.png">
  <link rel="stylesheet" href="./style.css">
  <title>Card Ledger</title>
  <script type="importmap">
    {"imports":{"react":"https://esm.sh/react@19.1.0","react/jsx-runtime":"https://esm.sh/react@19.1.0/jsx-runtime?external=react","react-dom/client":"https://esm.sh/react-dom@19.1.0/client?external=react","lucide-react":"https://esm.sh/lucide-react@0.468.0?external=react"}}
  </script>
  <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body><div id="root"></div><script type="module" src="./main.js"></script></body>
</html>
`);
console.log('Created CDN fallback build in dist/. For the optimized build, run npm run build.');
