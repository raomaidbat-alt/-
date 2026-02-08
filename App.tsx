
import React from 'react';
import Hero from './components/Hero';
import PainPoints from './components/PainPoints';
import Team from './components/Team';
import SystemicApproach from './components/SystemicApproach';
import Results from './components/Results';
import Program from './components/Program';
import Pricing from './components/Pricing';
import FAQ from './components/FAQ';
import Header from './components/Header';

const App: React.FC = () => {
  return (
    <div className="min-h-screen">
      <Header />
      <main>
        <Hero />
        <PainPoints />
        <Team />
        <SystemicApproach />
        <Results />
        <Program />
        <Pricing />
        <FAQ />
      </main>
      <footer className="bg-purple-900 text-white py-12 px-6">
        <div className="max-w-7xl mx-auto text-center">
          <p className="font-serif text-2xl mb-4">Старт восстановления</p>
          <p className="text-purple-300 text-sm">© 2025 Женское здоровье. Все права защищены.</p>
        </div>
      </footer>
    </div>
  );
};

export default App;
