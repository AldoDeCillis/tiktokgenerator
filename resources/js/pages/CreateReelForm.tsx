import React, { useState, FormEvent } from 'react';

const CreateReelForm: React.FC = () => {
  const [argomento, setArgomento] = useState<string>('');
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [loading, setLoading] = useState<boolean>(false);

  // Funzione di utilità per prendere il CSRF token dal meta tag
  const getCsrfToken = (): string | null => {
    const tag = document.querySelector('meta[name="csrf-token"]');
    return tag ? tag.getAttribute('content') : null;
  };

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);
    setSuccessMessage(null);

    if (!argomento.trim()) {
      setErrorMessage('L’argomento è obbligatorio.');
      return;
    }

    const csrfToken = getCsrfToken();
    if (!csrfToken) {
      setErrorMessage('CSRF token non trovato.');
      return;
    }

    setLoading(true);
    try {
      const response = await fetch('/api/reels', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,          // <- qui includi il token
        },
        body: JSON.stringify({ argomento }),
      });

      if (!response.ok) {
        const data = await response.json().catch(() => null);
        const msg =
          data?.message || `Errore durante la creazione (HTTP ${response.status})`;
        throw new Error(msg);
      }

      const data = await response.json();
      const newId: number = data.id;
      setSuccessMessage(`Reel creato! ID: ${newId}`);
      setArgomento('');
    } catch (err: any) {
      setErrorMessage(err.message || 'Errore sconosciuto.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="p-4 max-w-xl mx-auto bg-white rounded-md shadow">
      <h2 className="text-2xl font-semibold mb-4">Crea un nuovo Reel</h2>
      <form onSubmit={handleSubmit}>
        <div className="mb-3">
          <label
            htmlFor="argomento"
            className="block text-sm font-medium text-gray-700 mb-1"
          >
            Argomento
          </label>
          <input
            type="text"
            id="argomento"
            value={argomento}
            onChange={(e) => setArgomento(e.target.value)}
            placeholder="Es. Come allacciarsi le scarpe"
            className="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-400"
            disabled={loading}
          />
        </div>

        {errorMessage && (
          <div className="mb-3 text-red-600 text-sm">{errorMessage}</div>
        )}
        {successMessage && (
          <div className="mb-3 text-green-600 text-sm">{successMessage}</div>
        )}

        <button
          type="submit"
          disabled={loading}
          className={`px-4 py-2 rounded text-white ${
            loading
              ? 'bg-gray-400 cursor-not-allowed'
              : 'bg-indigo-600 hover:bg-indigo-700'
          }`}
        >
          {loading ? 'Invio in corso...' : 'Crea Reel'}
        </button>
      </form>
    </div>
  );
};

export default CreateReelForm;
