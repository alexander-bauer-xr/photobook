import React from 'react'
import ReactDOM from 'react-dom/client'
import App from './src/App'
import PrintView from './src/components/PrintView'
import './src/index.css'

const root = document.getElementById('photobook-root')!
const isPrint = root.dataset.print === 'true'
const hash = root.dataset.hash ?? ''

ReactDOM.createRoot(root).render(
  <React.StrictMode>
    {isPrint ? <PrintView hash={hash} /> : <App />}
  </React.StrictMode>,
)
