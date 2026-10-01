<template>
  <div class="page">
    <main class="page__panel privacy">
      <h1 class="privacy__title">Privacidad</h1>
      <p class="privacy__lead">
        Bingo Sorpresa es un proyecto personal. Guarda lo justo para que puedas montar y jugar tus
        bingos, y nada más.
      </p>

      <section>
        <h2>Qué se guarda</h2>
        <ul>
          <li>De tu cuenta de Google: tu <strong>email</strong>, tu <strong>nombre</strong> y tu <strong>avatar</strong>.</li>
          <li>Las <strong>imágenes que subes</strong> a tus bingos, y los bingos en sí (títulos, textos y ajustes).</li>
        </ul>
      </section>

      <section>
        <h2>Para qué</h2>
        <p>
          Solo para que la app funcione: identificarte al entrar y enseñarte tus bingos. No se usa
          para publicidad, no se vende y no se comparte con nadie.
        </p>
      </section>

      <section>
        <h2>Quién lo ve</h2>
        <p>
          Solo tú. Las imágenes son privadas y no tienen una dirección pública. Si activas el enlace
          compartido de un bingo, también lo ve <strong>quien tenga ese enlace</strong>; al
          desactivarlo, el enlace deja de funcionar.
        </p>
      </section>

      <section>
        <h2>Cómo borrarlo</h2>
        <p>
          Entra en <router-link to="/construir">Construir</router-link> y pulsa
          <strong>«Borrar cuenta»</strong>. Se borran al momento tu cuenta, tus bingos y todas tus
          imágenes, y no se pueden recuperar.
        </p>
      </section>

      <router-link class="page__back" to="/">← Volver a la portada</router-link>
    </main>
  </div>
</template>

<script>
export default {
  name: 'PrivacyView'
};
</script>

<style scoped lang="scss">
.privacy {
  line-height: 1.6;
}

.privacy__title {
  margin: 0 0 0.5rem;
  font-size: clamp(2rem, 5vw, 3rem);
  font-weight: 900;
  color: var(--accent-2);
}

.privacy__lead {
  margin: 0 0 1.5rem;
  color: var(--ink-dim);
}

h2 {
  margin: 1.5rem 0 0.4rem;
  font-size: clamp(1.15rem, 2.4vw, 1.5rem);
  color: var(--accent);
}

p,
ul {
  margin: 0;
}

ul {
  padding-left: 1.2em;
}

a {
  color: var(--accent-2);
}
</style>
