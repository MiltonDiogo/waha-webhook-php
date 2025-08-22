# Usa imagem oficial do PHP
FROM php:8.2-cli

# Copia todos arquivos pro container
WORKDIR /app
COPY . .

# Porta que o Render espera
EXPOSE 10000

# Comando para rodar o PHP embutido
CMD ["php", "-S", "0.0.0.0:10000", "-t", "/app"]
