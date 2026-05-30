import { createRoot } from 'react-dom/client';
import '../../css/nifty.css';
import App from './App';

const rootElement = document.getElementById('arbitrage-spa');

if (rootElement) {
    createRoot(rootElement).render(<App />);
}
