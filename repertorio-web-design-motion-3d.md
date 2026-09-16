# Repertório de Web Design, Motion e 3D para Agentes de Código

Quero montar um repertório de **web design, motion e 3D** para meu agente de código, para ele parar de gerar sites e sistemas genéricos.

## Recursos avaliados

1. **Frontend Design (skill oficial da Anthropic)**  
   <https://github.com/anthropics/claude-code/tree/main/plugins/frontend-design>

2. **claudedesignskills** — repositório com várias skills de design, motion e 3D, incluindo GSAP, Three.js, React Three Fiber (R3F) e scroll  
   <https://github.com/freshtechbro/claudedesignskills>

3. **Web 3D Integration Patterns** — parte do repositório acima; integra Three.js e motion à página  
   <https://github.com/freshtechbro/claudedesignskills/tree/main/.claude/skills/web3d-integration-patterns>

4. **Three.js Game Skills** — repositório independente para jogos 3D no navegador  
   <https://github.com/majidmanzarpour/threejs-game-skills>

5. **Scroll World** — projeto open source que transforma o scroll em uma experiência cinematográfica  
   <https://github.com/oso95/scroll-world>

## Perguntas obrigatórias antes de recomendar ou instalar

Antes de recomendar ou instalar qualquer coisa, pergunte:

- Qual projeto estou construindo e em qual stack?
- Qual é o objetivo visual: landing page premium, experiência 3D com scroll, jogo 3D no navegador ou site institucional?
- Já tenho alguma skill de design instalada?
- Qual agente de código e sistema operacional utilizo?
- Quais são as restrições de performance e mobile?
- Quanto desse repertório quero montar agora?

## Critérios de avaliação e recomendação

Explique como cada opção ajudaria no meu contexto, incluindo:

- Requisitos;
- Limitações;
- Riscos;
- Permissões necessárias;
- Sobreposições entre os recursos.

Considere explicitamente que:

- A opção 3, **Web 3D Integration Patterns**, pertence ao repositório da opção 2, **claudedesignskills**;
- A opção 4, **Three.js Game Skills**, é um projeto independente.

Identifique qual recurso tem maior impacto imediato. Em geral, **Frontend Design** é a base para qualquer projeto visual.

Selecione **no máximo três mudanças** para começar. Não recomende instalar tudo de uma vez.

## Verificação da documentação

Consulte o README e a documentação oficial atual de cada recurso antes de fornecer:

- Comandos;
- Versões;
- Configurações;
- Instruções de instalação.

Não invente links, comandos ou capacidades.

## Regras para instalação

Se eu autorizar a instalação:

1. Comece pela menor mudança reversível;
2. Informe quais arquivos foram alterados;
3. Explique como desfazer as alterações;
4. Nunca peça segredos, tokens ou credenciais em texto aberto.

## Exemplos práticos obrigatórios

Para cada skill escolhida, use um exemplo real e adequado ao recurso:

- **Frontend Design:** crie um trecho de landing page com direção de arte;
- **GSAP:** crie uma timeline com reveal e ScrollTrigger;
- **Web 3D Integration Patterns + Scroll World:** crie uma cena Three.js sincronizada ao scroll;
- **Three.js Game Skills:** crie um minigame 3D com apenas uma mecânica.

## Critérios de validação

Valide cada resultado com critérios concretos:

- Verificação visual no navegador;
- Meta de 60 fps no desktop;
- Leitura e conteúdo intactos no mobile;
- Compatibilidade com `prefers-reduced-motion`.

Considere o trabalho concluído somente depois dessas validações.