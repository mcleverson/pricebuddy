These repository instructions are mandatory and apply to every coding request. Always inspect the existing implementation before generating or modifying code. Repository code is the source of truth.

# PriceBuddy - Engineering Instructions

Estas instruções são obrigatórias para qualquer alteração neste repositório.

Antes de executar, editar ou sugerir código, leia estas instruções e inspecione a implementação existente relacionada à tarefa.

## 1. Regra principal: não invente a arquitetura

Nunca assuma que uma classe, tabela, campo, serviço, estratégia, provider, repository, migration ou fluxo existe.

Antes de propor uma alteração:

1. Localize o código atual relacionado ao problema.
2. Entenda o fluxo existente.
3. Identifique as entidades, tabelas, serviços, controllers, strategies e configurações realmente existentes.
4. Só então proponha ou implemente a mudança.

Se algo não puder ser confirmado no código, trate explicitamente como desconhecido.

Nunca invente estruturas apenas porque seriam comuns em outro projeto.

Exemplo de comportamento proibido:

* sugerir uma tabela `price_history` sem confirmar que ela existe ou que é necessária;
* criar um novo service/repository/provider quando o projeto já possui abstração equivalente;
* presumir nomes de campos do banco;
* presumir como um scraper funciona sem ler sua implementação;
* presumir que CSS Selector é a estratégia correta sem verificar as strategies disponíveis.

## 1.5. Agentes: nunca use seletores CSS ou HTML

O Hermes e demais agentes autônomos devem navegar como pessoas, interpretando texto e estrutura semântica da página. Não usam seletores CSS, classes, IDs ou XPath para extrair dados.

- Proibido: `document.querySelector('.s-image')`, `.a-price`, `.s-result-item`, `data-component-type`, XPath, etc.
- Permitido: observação textual da página, árvore de acessibilidade, schema.org, metadados declarativos e ações do agente (clicar, rolar, navegar).
- Quando for necessário obter imagem, preço original ou outros metadados, o agente deve abrir a página do produto e usar fontes declarativas (schema.org, Open Graph, JSON-LD) ou pedir à LLM para identificar a informação no texto visível.
- Se a extração via seletor HTML/CSS for necessária, isso é sinal de que o fluxo deve ser implementado via scrapers estruturados do PriceBuddy, não via agente.

## 2. Faça a menor mudança possível

O PriceBuddy é um MVP funcional em evolução.

Priorize:

* alterações pequenas;
* baixo acoplamento;
* reaproveitamento da arquitetura existente;
* código simples;
* implementação fácil de entender;
* baixo risco de regressão.

Evite:

* overengineering;
* abstrações prematuras;
* boilerplate;
* refatorações não relacionadas à tarefa;
* novos padrões arquiteturais quando os existentes resolvem o problema;
* criação de várias classes para resolver um problema simples;
* mudanças "para deixar preparado para o futuro" sem necessidade atual.

Se uma mudança puder ser feita alterando 2 arquivos em vez de criar uma nova camada com 6 arquivos, prefira a solução menor, desde que preserve a qualidade do código.

## 3. Preserve o que já funciona

Não reestruture fluxos existentes apenas porque existe uma solução considerada mais elegante.

Antes de alterar um fluxo existente, identifique:

* quem chama esse código;
* quais stores usam esse código;
* quais product sources usam esse código;
* quais strategies dependem dele;
* como ele interage com proxy, scraping, APIs, banco e filas;
* quais comportamentos podem sofrer regressão.

Mudanças para suportar um marketplace não devem quebrar os demais.

## 4. Scraping, APIs e providers não são a mesma coisa

O PriceBuddy trabalha com diferentes formas de obtenção de dados.

Não force uma integração via API a passar por uma arquitetura feita especificamente para scraping se isso não fizer sentido.

Da mesma forma, não altere a arquitetura de scraping existente apenas para acomodar uma API.

Antes de implementar um novo provider ou marketplace:

1. descubra como stores e product sources estão representados atualmente;
2. descubra como o sistema escolhe a estratégia de obtenção de dados;
3. descubra onde proxy e scraping entram no fluxo;
4. determine o menor ponto de extensão possível.

Integrações como Shopee via API devem coexistir com integrações baseadas em scraping sem criar duas aplicações diferentes dentro do PriceBuddy.

## 5. Proxy

O projeto já possui trabalho realizado para centralizar e dinamizar o uso de proxies nas chamadas de scraping.

Não:

* contorne essa implementação;
* crie outro sistema de proxy paralelo;
* espalhe configuração de proxy em diferentes scrapers;
* replique lógica já existente.

Antes de alterar qualquer chamada HTTP ou scraper, localize e compreenda a implementação atual de proxy.

Integrações que não utilizam scraping, como APIs oficiais, não precisam necessariamente utilizar o proxy de scraping. Verifique o fluxo antes de decidir.

## 6. Strategies de scraping

Não escolha CSS Selector automaticamente.

Antes de configurar ou alterar uma strategy:

1. verifique quais tipos de strategy o PriceBuddy realmente suporta;
2. leia a implementação dessas strategies;
3. analise o HTML ou resposta da origem;
4. determine qual strategy consegue identificar o dado de forma estável.

Sites como Amazon podem apresentar:

* vários preços simultaneamente no HTML;
* preço cheio;
* preço promocional;
* preço para modalidades específicas de pagamento;
* elementos visualmente ocultos;
* markup diferente conforme o produto.

Por isso, encontrar um seletor que retorna algum valor não significa que a strategy está correta.

Para `price` e `original price`, valide explicitamente qual elemento representa cada valor.

Não reutilize o mesmo selector/value para dois campos apenas porque ocasionalmente produz o resultado esperado.

## 7. Banco de dados

Nunca altere o schema sem primeiro inspecionar:

* models;
* migrations;
* tabelas existentes;
* relacionamentos;
* campos atualmente utilizados.

Mudança de banco deve existir somente quando a funcionalidade realmente exige persistência nova.

Quando uma alteração de schema for necessária:

* reutilize estruturas existentes quando possível;
* crie migration compatível com o padrão do projeto;
* preserve dados existentes;
* evite duplicação de informação.

Não crie campos apenas para facilitar momentaneamente uma implementação.

## 8. Dependências

Não adicione biblioteca, framework, serviço externo ou pacote novo se o projeto já possui capacidade equivalente.

Antes de adicionar uma dependência, responda internamente:

1. O projeto já possui algo que resolve isso?
2. Isso pode ser feito de maneira simples com a stack atual?
3. A dependência reduz mais complexidade do que adiciona?

Caso contrário, não adicione.

## 9. Docker, aplicação local e servidor

Não misture os ambientes.

Antes de fornecer comandos ou alterar configuração, determine se a execução está acontecendo:

* no host;
* dentro do container;
* via Docker Compose;
* no servidor;
* na máquina local.

Inspecione os arquivos Docker existentes antes de sugerir novos comandos de build, volumes, bancos ou serviços.

Não presuma paths, usuários Linux, portas ou nomes de containers.

## 10. Não faça refatoração incidental

Ao receber uma tarefa como:

"Adicionar suporte ao original price da Amazon"

não transforme isso em:

* refatoração de todos os scrapers;
* novo sistema de strategies;
* novo repository;
* reorganização geral dos models;
* alteração de nomenclatura;
* nova arquitetura de providers.

Resolva primeiro o problema solicitado.

Refatorações maiores só devem acontecer quando forem necessárias para implementar corretamente a funcionalidade.

## 11. Antes de escrever código

Para cada tarefa relevante, faça internamente esta sequência:

### A. Localizar

Encontre os arquivos diretamente relacionados ao comportamento solicitado.

### B. Entender

Trace o fluxo existente de entrada até saída.

### C. Confirmar

Confirme nomes de classes, métodos, tabelas, campos, strategies e configurações no código.

### D. Escolher

Identifique o menor ponto correto para a alteração.

### E. Implementar

Faça a mudança sem alterar comportamentos não relacionados.

### F. Validar

Execute os testes, comandos ou verificações adequados.

Nunca comece pela etapa E.

## 12. Quando houver dúvida

Investigue o repositório.

Não compense falta de informação criando uma hipótese e implementando-a como fato.

Prefira pesquisar:

* referências da classe;
* chamadas do método;
* migrations;
* configuração;
* testes;
* Docker Compose;
* interfaces;
* implementações de outros marketplaces;
* exemplos já existentes.

Se mesmo depois da investigação uma decisão continuar realmente ambígua, explique qual informação está faltando.

## 13. Validação

Nunca declare que uma alteração funciona apenas porque o código parece correto.

Quando possível:

* execute testes existentes;
* execute testes direcionados à mudança;
* rode lint/static analysis se já fizer parte do projeto;
* valide migrations;
* valide o container/build quando a alteração afetar runtime;
* teste o fluxo real afetado.

Informe claramente o que foi validado e o que não pôde ser validado.

## 14. Escopo atual do PriceBuddy

O objetivo é construir uma ferramenta prática para operar grupos de achadinhos e afiliados, não uma plataforma genérica de e-commerce.

Entre os fluxos relevantes estão:

* marketplaces como Shopee, Amazon, Mercado Livre, Magalu e AliExpress;
* coleta de produtos;
* stores;
* product sources;
* comparação de preços;
* SearXNG e fontes externas de comparação;
* identificação de preço atual e preço original quando disponível;
* adaptação para links de afiliados;
* cupons;
* aprovação de ofertas;
* geração posterior de mensagens usando LLM;
* publicação/distribuição das oportunidades.

Use esse objetivo para evitar funcionalidades que não contribuem para o produto atual.

## 15. Critério de decisão

Quando existirem duas soluções tecnicamente válidas, prefira nesta ordem:

1. a que reaproveita melhor o código existente;
2. a que modifica menos componentes;
3. a que é mais simples de manter;
4. a que introduz menos abstrações;
5. a que gera menor risco de regressão;
6. a que atende a necessidade atual sem tentar antecipar requisitos hipotéticos.

## 16. Formato esperado ao executar uma tarefa

Antes de uma mudança relevante, apresente de forma curta:

* o que foi encontrado no código;
* onde está o fluxo responsável;
* qual alteração será feita;
* por que esse é o menor ponto de mudança.

Depois da implementação, informe:

* arquivos alterados;
* comportamento alterado;
* validações executadas;
* qualquer ponto que permaneça sem validação.

Não apresente longas explicações genéricas quando uma resposta objetiva for suficiente.

## 17. Regra final

O código existente é a fonte da verdade.

Estas instruções e o contexto funcional ajudam a interpretar o projeto, mas nunca substituem a inspeção do repositório.

Quando documentação, suposição e código divergirem, primeiro identifique a divergência e use o comportamento real do código como base antes de fazer qualquer alteração.


# Database safety and test policy

- Preserve the user's local PriceBuddy database and Docker volumes. Treat them as production-like data.
- Do not create, modify, or run automated tests unless the user explicitly asks for test work.
- Never run `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, `RefreshDatabase`, or any equivalent destructive database operation against the `pricebuddy` database.
- Never run the test suite inside the normal `app` container or with the project's regular `.env` database settings.
- Tests that use a database must use a dedicated isolated database such as `pricebuddy_test`, `tests_db`, or an in-memory SQLite database.
- Before running any database-backed test, verify the effective runtime database name. If it is `pricebuddy`, empty, unavailable, or cannot be verified, abort the test and report the safety issue instead.
- Do not assume `APP_ENV=testing` makes the database safe. Confirm the effective connection, host, and database name.
- A test may use `RefreshDatabase` only after an isolated test database has been created and verified. Never fall back to the main database when the test database is unavailable.
- Do not delete or recreate Docker volumes as part of testing, troubleshooting, builds, or application restarts.
- Prefer read-only diagnostics. Ask the user before any operation that can delete or overwrite application data.
